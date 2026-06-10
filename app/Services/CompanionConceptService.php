<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

/**
 * Captures the companion meal concept automatically when a velada or a fin de
 * semana is approved (regla de Luis, 2026-06-10):
 *
 *   - Velada (night_shift)        → Cena   (CompensationType con pull rule meal)
 *   - Fin de Semana (weekend pull)→ Comida (CompensationType con pull rule comida)
 *
 * So the area head only authorizes the velada / weekend and the Cena / Comida
 * is created already approved, instead of being registered separately. The
 * companion is created ONLY when the employee has that concept active in their
 * catalog, and never duplicated for the same employee/day. Each companion is
 * linked back to its parent (generated_from_authorization_id) so it can be
 * rejected if the parent is reverted.
 */
class CompanionConceptService
{
    public function __construct(
        private ZktecoSyncService $syncService,
        private PayrollInvalidationService $payrollInvalidation,
    ) {}

    /**
     * Create + approve the companion concept for a just-approved velada / fin de
     * semana. Returns the created companion, or null when nothing applied (not a
     * velada/weekend, no active companion concept in the catalog, employee not
     * enrolled, or a companion already exists for that day).
     */
    public function captureForApproved(Authorization $parent): ?Authorization
    {
        $plan = $this->plan($parent);
        if ($plan === null) {
            return null;
        }
        [$companionType, $approver] = $plan;

        $companion = Authorization::create([
            'employee_id' => $parent->employee_id,
            'requested_by' => $parent->requested_by ?? $approver->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $companionType->id,
            'date' => $this->dateString($parent),
            'start_time' => null,
            'end_time' => null,
            'hours' => 1,
            'reason' => "Generado automáticamente al aprobar la autorización #{$parent->id} ({$companionType->name}).",
            'status' => Authorization::STATUS_PENDING,
            'is_pre_authorization' => false,
            'generated_from_authorization_id' => $parent->id,
            'department_head_id' => $parent->department_head_id,
        ]);

        $companion->approve($approver);
        $this->applyEffects($companion);

        return $companion;
    }

    /**
     * Whether captureForApproved() would create a companion for this parent
     * (same checks, no writes). Used by the backfill command's --dry-run.
     */
    public function wouldCapture(Authorization $parent): bool
    {
        return $this->plan($parent) !== null;
    }

    /**
     * Resolve the [companionType, approver] for a parent, or null when no
     * companion applies: not a velada/weekend, no active companion concept,
     * employee not enrolled, no approver, or a companion already exists.
     *
     * @return array{0: CompensationType, 1: User}|null
     */
    private function plan(Authorization $parent): ?array
    {
        $pullRule = $this->companionPullRule($parent);
        if ($pullRule === null) {
            return null;
        }

        $companionType = CompensationType::active()
            ->where('attendance_pull_rule', $pullRule)
            ->orderBy('priority')
            ->first();
        if (! $companionType) {
            return null;
        }

        // Solo si el empleado tiene el concepto asignado y activo (decisión Luis).
        $employee = $parent->employee ?? Employee::find($parent->employee_id);
        if (! $employee || ! $this->employeeHasConcept($employee, $companionType->id)) {
            return null;
        }

        // El acompañante hereda al aprobador del padre (ya está aprobado).
        $approver = $parent->approved_by ? User::find($parent->approved_by) : null;
        if (! $approver) {
            return null;
        }

        // Dedup: nunca duplicar la Cena/Comida. Se compara por CATEGORÍA de
        // concepto (la misma pull rule), no por el id exacto, para no crear una
        // segunda aunque ya exista por otra vía (capturada a mano o con otro id
        // del mismo tipo). Cuenta cualquier activa (pendiente/aprobada/pagada).
        $exists = Authorization::where('employee_id', $parent->employee_id)
            ->whereDate('date', $this->dateString($parent))
            ->whereIn('status', [
                Authorization::STATUS_PENDING,
                Authorization::STATUS_APPROVED,
                Authorization::STATUS_PAID,
            ])
            ->whereHas('compensationType', fn ($q) => $q->where('attendance_pull_rule', $pullRule))
            ->exists();
        if ($exists) {
            return null;
        }

        return [$companionType, $approver];
    }

    /**
     * Reject the companions auto-generated by this parent (when the parent is
     * reverted). Only touches still-active companions — never a paid one. Each
     * rejection recalculates attendance and invalidates payroll. Returns the
     * number of companions rejected.
     */
    public function rejectCompanionsOf(Authorization $parent, User $rejector, string $reason): int
    {
        $companions = Authorization::where('generated_from_authorization_id', $parent->id)
            ->whereIn('status', [Authorization::STATUS_PENDING, Authorization::STATUS_APPROVED])
            ->get();

        foreach ($companions as $companion) {
            $companion->reject($rejector, $reason);
            $this->applyEffects($companion);
        }

        return $companions->count();
    }

    private function companionPullRule(Authorization $parent): ?string
    {
        if ($parent->type === Authorization::TYPE_NIGHT_SHIFT) {
            return CompensationType::PULL_RULE_MEAL; // Cena
        }

        $compensationType = $parent->compensation_type_id
            ? CompensationType::find($parent->compensation_type_id)
            : null;

        if ($compensationType && $compensationType->hasWeekendPullRule()) {
            return CompensationType::PULL_RULE_COMIDA; // Comida
        }

        return null;
    }

    private function employeeHasConcept(Employee $employee, int $compensationTypeId): bool
    {
        return $employee->compensationTypes()
            ->wherePivot('is_active', true)
            ->where('compensation_types.id', $compensationTypeId)
            ->exists();
    }

    /**
     * Recalc the day's attendance record and invalidate the payroll periods
     * covering the date — the same side-effects the controller runs for these
     * per-day concepts (DECISIONES §7).
     */
    private function applyEffects(Authorization $authorization): void
    {
        $record = AttendanceRecord::where('employee_id', $authorization->employee_id)
            ->whereDate('work_date', $this->dateString($authorization))
            ->first();

        if ($record) {
            $this->syncService->recalculateAttendanceRecord($record);
        }

        $this->payrollInvalidation->invalidate(
            $authorization->employee_id,
            $this->dateString($authorization),
        );
    }

    private function dateString(Authorization $authorization): string
    {
        return $authorization->date instanceof Carbon
            ? $authorization->date->toDateString()
            : Carbon::parse($authorization->date)->toDateString();
    }
}
