<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Regla única de unidades FIN: la checada decide qué se puede aprobar y la
 * autorización aprobada decide qué se materializa después.
 */
class WeekendAuthorizationUnitService
{
    /** Cantidad comprometida por los FIN aprobados, deduplicada por día. */
    public function approvedUnits(Collection $authorizations, ?array $allowedPaymentPeriods = null): int
    {
        return (int) $authorizations
            ->filter(fn (Authorization $authorization) => $authorization->compensationType?->hasWeekendPullRule()
                && in_array($authorization->status, [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID], true)
                && ($allowedPaymentPeriods === null || in_array(
                    $authorization->compensationType?->payment_period ?? CompensationType::PAYMENT_PERIOD_MONTHLY,
                    $allowedPaymentPeriods,
                    true,
                )))
            ->groupBy(fn (Authorization $authorization) => Carbon::parse($authorization->date)->toDateString())
            ->sum(fn (Collection $rows) => max(1, (int) round((float) $rows->max('hours'))));
    }

    /**
     * Materializa el snapshot aprobado cuando la autorización tiene horario.
     * Las filas históricas sin horario conservan la compatibilidad anterior,
     * donde hours=1 era un marcador y la checada aportaba las unidades reales.
     */
    public function materializedUnits(
        Collection $authorizations,
        Collection $attendance,
        Employee $employee,
        ?array $allowedPaymentPeriods = null,
    ): int {
        $recordsByDate = $attendance->keyBy(fn (AttendanceRecord $record) => Carbon::parse($record->work_date)->toDateString());

        return (int) $authorizations
            ->filter(fn (Authorization $authorization) => $authorization->compensationType?->hasWeekendPullRule()
                && in_array($authorization->status, [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID], true)
                && ($allowedPaymentPeriods === null || in_array(
                    $authorization->compensationType?->payment_period ?? CompensationType::PAYMENT_PERIOD_MONTHLY,
                    $allowedPaymentPeriods,
                    true,
                )))
            ->groupBy(fn (Authorization $authorization) => Carbon::parse($authorization->date)->toDateString())
            ->map(function (Collection $rows, string $date) use ($recordsByDate, $employee) {
                $approved = max(1, (int) round((float) $rows->max('hours')));
                $hasCapturedSchedule = $rows->contains(fn (Authorization $authorization) => $authorization->start_time !== null && $authorization->end_time !== null);

                if ($hasCapturedSchedule) {
                    return $approved;
                }

                return max($approved, $this->backedUnitsFor($employee, $recordsByDate->get($date)) ?? 0);
            })
            ->sum();
    }

    /**
     * Unidades que respalda hoy la checada para un FIN. Null significa que no
     * es FIN o que no existe una checada completa contra la cual validarlo.
     */
    public function backedUnits(Authorization $authorization): ?int
    {
        $authorization->loadMissing(['employee.department', 'compensationType']);
        if (! $authorization->compensationType?->hasWeekendPullRule()) {
            return null;
        }

        $date = Carbon::parse($authorization->date);
        $record = AttendanceRecord::query()
            ->where('employee_id', $authorization->employee_id)
            ->whereDate('work_date', $date->toDateString())
            ->first();

        if (! $date->isWeekend() && ! Holiday::isHoliday($date->toDateString())
            && ! (bool) ($record?->is_weekend_work)) {
            return 0;
        }

        return $this->backedUnitsFor($authorization->employee, $record);
    }

    public function backedUnitsFor(Employee $employee, ?AttendanceRecord $record): ?int
    {
        $gross = $record?->grossSpanHours();
        if ($gross === null) {
            return null;
        }

        $base = max(0.0, (float) $gross - (float) ($record->velada_hours ?? 0));
        $unitHours = $employee->department?->weekend_unit_hours;
        if ($unitHours !== null && (int) $unitHours > 0) {
            return max(1, (int) floor($base / (int) $unitHours));
        }

        return (int) ($employee->weekendUnitsForGrossHours($base) ?? 0);
    }

    /**
     * Convierte el horario capturado en la cantidad FIN que representa. Esta es
     * la misma regla de negocio usada con checadas, pero permite preautorizar
     * un día todavía no trabajado. Si la salida es menor al inicio, cruza
     * medianoche; horas iguales representan un rango vacío.
     */
    public function unitsForTimeRange(Employee $employee, ?string $startTime, ?string $endTime): ?int
    {
        if (empty($startTime) || empty($endTime)) {
            return null;
        }

        $start = Carbon::createFromFormat('H:i', substr($startTime, 0, 5));
        $end = Carbon::createFromFormat('H:i', substr($endTime, 0, 5));
        if ($end->lt($start)) {
            $end->addDay();
        }

        $grossHours = $start->diffInMinutes($end) / 60;
        if ($grossHours <= 0) {
            return 0;
        }

        $unitHours = $employee->department?->weekend_unit_hours;
        if ($unitHours !== null && (int) $unitHours > 0) {
            return max(1, (int) floor($grossHours / (int) $unitHours));
        }

        return (int) ($employee->weekendUnitsForGrossHours($grossHours) ?? 0);
    }

    public function requestedUnits(Authorization $authorization, ?float $overrideHours = null): int
    {
        return max(1, (int) round($overrideHours ?? (float) $authorization->hours));
    }

    /** Con checada completa, la aprobación debe coincidir con sus unidades. */
    public function differsFromBackedUnits(Authorization $authorization, ?float $overrideHours = null): bool
    {
        $backed = $this->backedUnits($authorization);

        return $backed !== null && $this->requestedUnits($authorization, $overrideHours) !== $backed;
    }
}
