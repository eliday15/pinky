<?php

namespace App\Services;

use App\Models\BreakfastClaim;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio del kiosco de desayunos.
 *
 * Un empleado puede cobrar su desayuno únicamente dentro de la ventana
 * configurable ANTES de su hora de entrada de ese día (a la hora de entrada en
 * punto ya no se entrega), máximo uno por día, validando su contraseña de
 * cobro (la MISMA del cobro de nómina en efectivo, cash_pin) y la
 * verificación facial. El costo vigente se congela en cada cobro; la nómina
 * semanal del vendedor suma esos snapshots.
 */
class BreakfastClaimService
{
    /**
     * Get the claim window for the employee on the given moment's date.
     *
     * Returns ['start' => Carbon, 'end' => Carbon, 'entry_time' => string]
     * where the window is [entry - window_minutes, entry). Returns null when
     * the employee has no schedule, the day is not a working day, or the day
     * schedule has no entry time.
     */
    public function claimWindowFor(Employee $employee, Carbon $now): ?array
    {
        $dayName = strtolower($now->format('l'));

        if (! $employee->isEffectiveWorkingDay($dayName)) {
            return null;
        }

        $daySchedule = $employee->getEffectiveScheduleForDay($dayName);

        if (! $daySchedule || empty($daySchedule->entry_time)) {
            return null;
        }

        $entry = Carbon::parse($now->toDateString().' '.$daySchedule->entry_time, $now->getTimezone());
        $windowMinutes = (int) SystemSetting::get('breakfast_window_minutes', 60);

        return [
            'start' => $entry->copy()->subMinutes($windowMinutes),
            'end' => $entry,
            'entry_time' => $entry->format('H:i'),
        ];
    }

    /**
     * Get the kiosk eligibility status for an employee right now.
     *
     * Returns ['eligible' => bool, 'reason' => ?string, 'window' => ?array]
     * so the kiosk can explain why a claim is not available before the
     * employee goes through the face/PIN steps.
     */
    public function statusFor(Employee $employee, Carbon $now): array
    {
        $window = $this->claimWindowFor($employee, $now);
        $windowPayload = $window ? [
            'start' => $window['start']->format('H:i'),
            'end' => $window['end']->format('H:i'),
            'entry_time' => $window['entry_time'],
        ] : null;

        $fail = fn (string $reason) => [
            'eligible' => false,
            'reason' => $reason,
            'window' => $windowPayload,
        ];

        if ($employee->status !== 'active') {
            return $fail('El empleado no está activo.');
        }

        if (empty($employee->photo_path)) {
            return $fail('El empleado no tiene foto registrada. Acude a RRHH para tomarla.');
        }

        if (! $employee->hasCashPin()) {
            return $fail('El empleado no tiene contraseña de cobro. Acude a RRHH para configurarla.');
        }

        // Ventana Abierta (Pruebas) — Luis 2026-08-18: con el switch activo en
        // Configuración > Desayunos se entrega a cualquier hora y cualquier día
        // (se saltan la ventana y el horario). Activo/foto/NIP/1-por-día siguen.
        $openAllDay = (bool) SystemSetting::get('breakfast_open_all_day', false);

        if (! $openAllDay) {
            if ($window === null) {
                return $fail('Hoy no es un día laborable del empleado o no tiene horario asignado.');
            }

            if ($now->lt($window['start'])) {
                return $fail("Aún es temprano: el desayuno se entrega a partir de las {$window['start']->format('H:i')}.");
            }

            if ($now->gte($window['end'])) {
                return $fail("Fuera de horario: tu hora de entrada era a las {$window['entry_time']} y el desayuno solo se entrega antes.");
            }
        }

        if ($this->alreadyClaimedToday($employee, $now)) {
            return $fail('Ya cobraste tu desayuno de hoy.');
        }

        return ['eligible' => true, 'reason' => null, 'window' => $windowPayload];
    }

    /**
     * Validate every business rule and register the breakfast claim.
     *
     * The PIN and the time window are the hard server-side gates; the face
     * match distance reported by the kiosk is re-checked against the
     * configured threshold and stored (with the snapshot) as evidence.
     *
     * @throws ValidationException When any business rule fails.
     */
    public function validateAndCreate(
        Employee $employee,
        string $pin,
        ?float $faceDistance,
        ?string $evidenceBase64,
        User $registrar,
    ): BreakfastClaim {
        $now = Carbon::now();

        $status = $this->statusFor($employee, $now);
        if (! $status['eligible']) {
            throw ValidationException::withMessages(['claim' => $status['reason']]);
        }

        if (! $employee->verifyCashPin($pin)) {
            throw ValidationException::withMessages(['pin' => 'Contraseña de cobro incorrecta.']);
        }

        $maxDistance = (float) SystemSetting::get('breakfast_face_max_distance', 0.5);
        if ($faceDistance === null || $faceDistance > $maxDistance) {
            throw ValidationException::withMessages([
                'face' => 'La verificación facial no fue aceptada. Intenta de nuevo frente a la cámara.',
            ]);
        }

        $evidencePath = $evidenceBase64 !== null
            ? $this->storeEvidence($employee, $now, $evidenceBase64)
            : null;

        return BreakfastClaim::create([
            'employee_id' => $employee->id,
            'claim_date' => $now->toDateString(),
            'claimed_at' => $now,
            'unit_cost' => (float) SystemSetting::get('breakfast_cost', 0),
            'face_match_distance' => round($faceDistance, 4),
            'evidence_photo_path' => $evidencePath,
            'registered_by' => $registrar->id,
        ]);
    }

    /**
     * Whether the employee already claimed a breakfast on the given date.
     */
    public function alreadyClaimedToday(Employee $employee, Carbon $now): bool
    {
        return BreakfastClaim::where('employee_id', $employee->id)
            ->whereDate('claim_date', $now->toDateString())
            ->exists();
    }

    /**
     * Persist the kiosk camera snapshot as auditable evidence.
     *
     * Accepts a base64 data-URI (or raw base64) JPEG/PNG and returns the
     * stored path on the public disk, or null when the payload is invalid —
     * a bad snapshot must not block a claim that already passed every gate.
     */
    private function storeEvidence(Employee $employee, Carbon $now, string $base64): ?string
    {
        $data = $base64;
        $extension = 'jpg';

        if (preg_match('#^data:image/(png|jpe?g);base64,#i', $base64, $matches)) {
            $extension = strtolower($matches[1]) === 'png' ? 'png' : 'jpg';
            $data = substr($base64, strpos($base64, ',') + 1);
        }

        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $path = sprintf(
            'breakfasts/evidence/%s/%s_%s.%s',
            $now->format('Y-m-d'),
            $employee->id,
            Str::random(8),
            $extension,
        );

        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
