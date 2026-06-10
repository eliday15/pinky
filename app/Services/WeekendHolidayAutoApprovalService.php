<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;

/**
 * Auto-approves weekend / holiday per-day authorizations.
 *
 * Business rule (Luis, 2026-06-10): if an employee has BOTH an entry and an
 * exit punch on a Saturday/Sunday worked outside their schedule, or on an
 * official holiday, the matching "Fin de Semana" (weekend pull) and "Comida"
 * (comida pull) authorizations — plus "Día Festivo" (holiday_worked) on
 * holidays — are approved automatically, without waiting for a human reviewer.
 *
 * The "Cena" (meal pull) concept is intentionally excluded: it also fires on
 * weekday long shifts / veladas, so it keeps requiring manual review.
 *
 * NOTE on post-approval effects: anomaly auto-resolution only targets
 * overtime/night_shift authorizations, so it is inert for these per-day
 * concepts. The effects therefore reduce to recalculating the day's attendance
 * record and invalidating the payroll periods that cover the date — the same
 * side-effects AuthorizationController::applyApprovalEffects() runs for them
 * (DECISIONES §7).
 */
class WeekendHolidayAutoApprovalService
{
    public function __construct(
        private ZktecoSyncService $syncService,
        private PayrollInvalidationService $payrollInvalidation,
    ) {}

    /**
     * Approve the authorization (signed by $approver) when it qualifies for
     * weekend/holiday auto-approval. Returns true if it was approved.
     */
    public function autoApprove(Authorization $authorization, User $approver): bool
    {
        if (! $authorization->isPending() || ! $this->qualifies($authorization)) {
            return false;
        }

        $authorization->approve($approver);
        $this->applyEffects($authorization);

        return true;
    }

    /**
     * Whether this is a Fin de Semana / Comida / Día Festivo authorization whose
     * day has both an entry and an exit punch, on a worked weekend or a holiday.
     */
    public function qualifies(Authorization $authorization): bool
    {
        $compensationType = $authorization->compensation_type_id
            ? CompensationType::find($authorization->compensation_type_id)
            : null;

        $isWeekendConcept = $compensationType
            && ($compensationType->hasWeekendPullRule() || $compensationType->hasComidaPullRule());

        $isHolidayConcept = $authorization->type === Authorization::TYPE_HOLIDAY_WORKED
            || ($compensationType && $compensationType->authorization_type === Authorization::TYPE_HOLIDAY_WORKED);

        if (! $isWeekendConcept && ! $isHolidayConcept) {
            return false;
        }

        $dateString = $this->dateString($authorization);

        $record = AttendanceRecord::where('employee_id', $authorization->employee_id)
            ->whereDate('work_date', $dateString)
            ->first();

        // Requiere checada de entrada Y salida ese día.
        if (! $record || ! $record->check_in || ! $record->check_out) {
            return false;
        }

        $isHoliday = Holiday::isHoliday($dateString);

        // Día Festivo: solo si el día realmente es festivo oficial.
        if ($isHolidayConcept && ! $isWeekendConcept) {
            return $isHoliday;
        }

        // Fin de Semana / Comida: fin de semana trabajado (fuera de horario)
        // o festivo.
        return (bool) $record->is_weekend_work || $isHoliday;
    }

    /**
     * Post-approval side-effects for these per-day concepts: recalc the day's
     * attendance record and invalidate the payroll periods covering the date.
     */
    private function applyEffects(Authorization $authorization): void
    {
        $dateString = $this->dateString($authorization);

        $record = $authorization->attendance_record_id
            ? $authorization->attendanceRecord
            : AttendanceRecord::where('employee_id', $authorization->employee_id)
                ->whereDate('work_date', $dateString)
                ->first();

        if ($record) {
            $this->syncService->recalculateAttendanceRecord($record);
        }

        $this->payrollInvalidation->invalidate($authorization->employee_id, $dateString);
    }

    private function dateString(Authorization $authorization): string
    {
        return $authorization->date instanceof Carbon
            ? $authorization->date->toDateString()
            : Carbon::parse($authorization->date)->toDateString();
    }
}
