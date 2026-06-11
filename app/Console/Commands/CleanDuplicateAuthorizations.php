<?php

namespace App\Console\Commands;

use App\Models\Authorization;
use App\Models\AttendanceRecord;
use App\Models\CompensationType;
use App\Models\User;
use App\Services\PayrollInvalidationService;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Finds active per-day concept authorizations duplicated for the same employee,
 * day and concept (Cena, Comida, Fin de Semana, Festivo, Dominical…). Payroll
 * pays 1 row = 1 day, so duplicates double-pay. Reports by default; --fix
 * rejects the extras (keeping the strongest/earliest), never touching a PAID
 * row (that already went out — needs manual review).
 */
class CleanDuplicateAuthorizations extends Command
{
    protected $signature = 'authorizations:clean-duplicate-concepts
                            {--fix : Rechazar las copias sobrantes (sin esto solo reporta)}';

    protected $description = 'Detecta y limpia autorizaciones de conceptos por día duplicadas (mismo empleado, día y concepto).';

    private const STATUS_RANK = [
        Authorization::STATUS_PAID => 3,
        Authorization::STATUS_APPROVED => 2,
        Authorization::STATUS_PENDING => 1,
    ];

    public function handle(ZktecoSyncService $syncService, PayrollInvalidationService $payrollInvalidation): int
    {
        $fix = (bool) $this->option('fix');

        $rejector = User::role('admin')->orderBy('id')->first();
        if ($fix && ! $rejector) {
            $this->error('No hay un usuario con rol admin para firmar los rechazos.');

            return self::FAILURE;
        }

        $perDayTypeIds = CompensationType::where('application_mode', CompensationType::APPLICATION_PER_DAY)
            ->pluck('id');

        $auths = Authorization::whereIn('status', array_keys(self::STATUS_RANK))
            ->whereNotNull('compensation_type_id')
            ->whereIn('compensation_type_id', $perDayTypeIds)
            ->orderBy('id')
            ->get(['id', 'employee_id', 'date', 'compensation_type_id', 'status']);

        $groups = $auths->groupBy(
            fn (Authorization $a) => $a->employee_id.'|'.Carbon::parse($a->date)->toDateString().'|'.$a->compensation_type_id
        )->filter(fn ($group) => $group->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No se encontraron conceptos por día duplicados.');

            return self::SUCCESS;
        }

        $rejected = 0;
        $paidConflicts = 0;
        foreach ($groups as $group) {
            // Keeper: el de estatus más firme y, a igualdad, el más antiguo.
            // El grupo ya viene ordenado por id (orderBy arriba) y sortByDesc es
            // estable, así que a igual rango se conserva el id más bajo.
            $sorted = $group->sortByDesc(fn (Authorization $a) => self::STATUS_RANK[$a->status] ?? 0)->values();
            $keeper = $sorted->first();
            $extras = $sorted->slice(1);

            $this->line("Duplicado: empleado {$keeper->employee_id}, {$keeper->date}, concepto {$keeper->compensation_type_id} — {$group->count()} filas (#{$group->pluck('id')->implode(', #')}); se conserva #{$keeper->id}");

            foreach ($extras as $extra) {
                if ($extra->status === Authorization::STATUS_PAID) {
                    $this->warn("  #{$extra->id} ya está PAGADA: requiere revisión manual de nómina.");
                    $paidConflicts++;

                    continue;
                }

                if (! $fix) {
                    $this->line("  #{$extra->id} se rechazaría.");
                    $rejected++;

                    continue;
                }

                $extra->reject($rejector, "Duplicado removido en limpieza de conceptos (se conserva #{$keeper->id}).");
                $this->applyEffects($extra, $syncService, $payrollInvalidation);
                $this->info("  #{$extra->id} rechazada.");
                $rejected++;
            }
        }

        $this->newLine();
        $this->info(($fix ? 'Copias rechazadas: ' : 'Copias que se rechazarían: ').$rejected);
        if ($paidConflicts > 0) {
            $this->warn("Copias ya PAGADAS (revisión manual): {$paidConflicts}");
        }
        if (! $fix) {
            $this->line('Corre con --fix para aplicar.');
        }

        return self::SUCCESS;
    }

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
