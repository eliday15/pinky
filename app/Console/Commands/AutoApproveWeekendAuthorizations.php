<?php

namespace App\Console\Commands;

use App\Models\Authorization;
use App\Models\User;
use App\Services\WeekendHolidayAutoApprovalService;
use Illuminate\Console\Command;

/**
 * Sweeps PENDING authorizations and auto-approves the weekend/holiday per-day
 * concepts (Fin de Semana, Comida, Día Festivo) whose day has both an entry and
 * an exit punch — the retroactive counterpart to the auto-approval that runs at
 * creation time (regla de Luis, 2026-06-10). Use --dry-run to preview.
 */
class AutoApproveWeekendAuthorizations extends Command
{
    protected $signature = 'authorizations:auto-approve-weekend
                            {--dry-run : Solo listar las que se aprobarían, sin aprobar}';

    protected $description = 'Aprueba autorizaciones pendientes de Fin de Semana, Comida y Día Festivo cuando hay checada de entrada y salida ese día.';

    public function handle(WeekendHolidayAutoApprovalService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // El barrido firma las aprobaciones con un admin del sistema (no hay
        // usuario autenticado en consola).
        $approver = User::role('admin')->orderBy('id')->first();
        if (! $approver) {
            $this->error('No hay un usuario con rol admin para firmar las aprobaciones.');

            return self::FAILURE;
        }

        $pending = Authorization::with('compensationType')
            ->where('status', Authorization::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        $count = 0;
        foreach ($pending as $authorization) {
            if (! $service->qualifies($authorization)) {
                continue;
            }

            $label = "#{$authorization->id} (empleado {$authorization->employee_id}, {$authorization->date})";

            if ($dryRun) {
                $this->line("[dry-run] se aprobaría {$label}");
                $count++;

                continue;
            }

            if ($service->autoApprove($authorization, $approver)) {
                $this->info("Aprobada {$label}");
                $count++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Calificarían: ' : 'Aprobadas: ').$count);

        return self::SUCCESS;
    }
}
