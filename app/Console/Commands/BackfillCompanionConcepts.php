<?php

namespace App\Console\Commands;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Services\CompanionConceptService;
use Illuminate\Console\Command;

/**
 * Backfills the companion meal concept for veladas / fines de semana that were
 * already approved before the auto-capture existed: velada → Cena,
 * fin de semana → Comida. Idempotent (dedups), respects per-employee catalog.
 *
 * By default only APPROVED parents are processed; PAID parents are skipped so a
 * closed payroll period is not reopened — pass --include-paid to include them.
 */
class BackfillCompanionConcepts extends Command
{
    protected $signature = 'authorizations:backfill-companion-concepts
                            {--dry-run : Solo listar lo que se crearía, sin crear}
                            {--include-paid : Incluir también las autorizaciones ya pagadas}';

    protected $description = 'Genera la Cena/Comida faltante de las veladas y fines de semana ya aprobados.';

    public function handle(CompanionConceptService $service): int
    {
        $includePaid = (bool) $this->option('include-paid');
        $dryRun = (bool) $this->option('dry-run');

        $statuses = $includePaid
            ? [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID]
            : [Authorization::STATUS_APPROVED];

        $weekendTypeIds = CompensationType::where('attendance_pull_rule', CompensationType::PULL_RULE_WEEKEND)
            ->pluck('id');

        $parents = Authorization::with('compensationType')
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($weekendTypeIds) {
                $q->where('type', Authorization::TYPE_NIGHT_SHIFT)
                    ->orWhereIn('compensation_type_id', $weekendTypeIds);
            })
            ->orderBy('id')
            ->get();

        $created = 0;
        foreach ($parents as $parent) {
            if (! $service->wouldCapture($parent)) {
                continue;
            }

            $label = "madre #{$parent->id} (empleado {$parent->employee_id}, {$parent->date})";

            if ($dryRun) {
                $this->line("[dry-run] generaría acompañante de {$label}");
                $created++;

                continue;
            }

            if ($service->captureForApproved($parent)) {
                $this->info("Acompañante creado para {$label}");
                $created++;
            }
        }

        $skippedPaid = ! $includePaid
            ? Authorization::where('status', Authorization::STATUS_PAID)
                ->where(function ($q) use ($weekendTypeIds) {
                    $q->where('type', Authorization::TYPE_NIGHT_SHIFT)
                        ->orWhereIn('compensation_type_id', $weekendTypeIds);
                })
                ->count()
            : 0;

        $this->newLine();
        $this->info(($dryRun ? 'Se crearían: ' : 'Acompañantes creados: ').$created);
        if ($skippedPaid > 0) {
            $this->warn("Veladas/fines de semana ya PAGADOS omitidos: {$skippedPaid} (usa --include-paid para incluirlos).");
        }

        return self::SUCCESS;
    }
}
