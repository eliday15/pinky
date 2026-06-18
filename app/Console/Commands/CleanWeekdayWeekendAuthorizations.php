<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\User;
use App\Services\PayrollInvalidationService;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Finds active "Fin de Semana" (weekend pull) authorizations whose date is NOT a
 * weekend day. A weekend concept can only land on a Saturday/Sunday, so any other
 * day is impossible — these were produced by a bug in the bulk-create screen
 * (CreateBulk.vue) that injected an extra row dated "today" per employee on top of
 * the real pulled weekend dates. Reports by default; --fix rejects them (recalc
 * attendance + invalidate payroll, the same side-effects an aprobación reversal
 * runs), never touching a PAID row (that already went out — manual review).
 */
class CleanWeekdayWeekendAuthorizations extends Command
{
    protected $signature = 'authorizations:clean-weekday-weekend
                            {--fix : Rechazar las filas (sin esto solo reporta)}';

    protected $description = 'Detecta y limpia autorizaciones de Fin de Semana caídas en día entre semana (basura del bug de alta masiva).';

    public function handle(ZktecoSyncService $syncService, PayrollInvalidationService $payrollInvalidation): int
    {
        $fix = (bool) $this->option('fix');

        $rejector = User::role('admin')->orderBy('id')->first();
        if ($fix && ! $rejector) {
            $this->error('No hay un usuario con rol admin para firmar los rechazos.');

            return self::FAILURE;
        }

        // Conceptos con regla de fin de semana (normalmente "Fin De Semana"/FIN).
        $weekendTypeIds = CompensationType::where('attendance_pull_rule', CompensationType::PULL_RULE_WEEKEND)
            ->pluck('id');
        if ($weekendTypeIds->isEmpty()) {
            $this->info('No hay conceptos con regla de fin de semana.');

            return self::SUCCESS;
        }

        $auths = Authorization::whereIn('compensation_type_id', $weekendTypeIds)
            ->whereIn('status', [
                Authorization::STATUS_PENDING,
                Authorization::STATUS_APPROVED,
                Authorization::STATUS_PAID,
            ])
            ->orderBy('id')
            ->get(['id', 'employee_id', 'date', 'compensation_type_id', 'status']);

        // Un fin de semana solo puede caer en sábado o domingo. Cualquier otra
        // fecha es imposible → basura del bug (incluye festivos entre semana, que
        // serían "Día Festivo", no Fin de Semana).
        $bogus = $auths->filter(fn (Authorization $a) => ! Carbon::parse($a->date)->isWeekend());

        if ($bogus->isEmpty()) {
            $this->info('No se encontraron Fin de Semana en día entre semana.');

            return self::SUCCESS;
        }

        $rejected = 0;
        $paidConflicts = 0;
        foreach ($bogus as $auth) {
            $dia = Carbon::parse($auth->date)->format('l');

            if ($auth->status === Authorization::STATUS_PAID) {
                $this->warn("  #{$auth->id} (empleado {$auth->employee_id}, {$auth->date} {$dia}) ya está PAGADA: requiere revisión manual de nómina.");
                $paidConflicts++;

                continue;
            }

            if (! $fix) {
                $this->line("  #{$auth->id} (empleado {$auth->employee_id}, {$auth->date} {$dia}, {$auth->status}) se rechazaría.");
                $rejected++;

                continue;
            }

            $auth->reject($rejector, 'Fin de Semana en día entre semana (basura del bug de alta masiva).');
            $this->applyEffects($auth, $syncService, $payrollInvalidation);
            $this->info("  #{$auth->id} (empleado {$auth->employee_id}, {$auth->date} {$dia}) rechazada.");
            $rejected++;
        }

        $this->newLine();
        $this->info(($fix ? 'Filas rechazadas: ' : 'Filas que se rechazarían: ').$rejected);
        if ($paidConflicts > 0) {
            $this->warn("Filas ya PAGADAS (revisión manual): {$paidConflicts}");
        }
        if (! $fix) {
            $this->line('Corre con --fix para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Recalc the day's attendance record and invalidate the payroll periods that
     * cover the date — the same side-effects the controller runs when reverting an
     * approval, so rejecting an already-approved row leaves payroll consistent.
     */
    private function applyEffects(
        Authorization $authorization,
        ZktecoSyncService $syncService,
        PayrollInvalidationService $payrollInvalidation,
    ): void {
        $dateString = Carbon::parse($authorization->date)->toDateString();

        $record = AttendanceRecord::where('employee_id', $authorization->employee_id)
            ->whereDate('work_date', $dateString)
            ->first();
        if ($record) {
            $syncService->recalculateAttendanceRecord($record);
        }

        $payrollInvalidation->invalidate($authorization->employee_id, $dateString);
    }
}
