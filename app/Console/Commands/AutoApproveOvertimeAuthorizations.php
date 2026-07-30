<?php

namespace App\Console\Commands;

use App\Http\Controllers\AuthorizationController;
use App\Models\Authorization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Barre las autorizaciones de TIEMPO EXTRA pendientes y auto-aprueba las que la
 * checada RESPALDA — la contraparte retroactiva de la auto-aprobación que corre
 * al capturarlas (regla de Luis, 2026-07-30). Deja pendientes las que reclaman
 * más de lo que la checada demuestra. Usa --dry-run para previsualizar.
 *
 * Espejo de AutoApproveWeekendAuthorizations para los conceptos por hora.
 */
class AutoApproveOvertimeAuthorizations extends Command
{
    protected $signature = 'authorizations:auto-approve-overtime
                            {--dry-run : Solo listar las que se aprobarían, sin aprobar}';

    protected $description = 'Aprueba autorizaciones pendientes de tiempo extra cuando la checada respalda la ventana autorizada.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // El barrido firma las aprobaciones con un admin del sistema (no hay
        // usuario autenticado en consola). Se autentica para que approve() y los
        // efectos corran igual que en la app.
        $approver = User::role('admin')->orderBy('id')->first()
            ?? User::role('superadmin')->orderBy('id')->first();
        if (! $approver) {
            $this->error('No hay un usuario con rol admin/superadmin para firmar las aprobaciones.');

            return self::FAILURE;
        }
        Auth::login($approver);

        $controller = app(AuthorizationController::class);

        $pending = Authorization::where('status', Authorization::STATUS_PENDING)
            ->where('type', Authorization::TYPE_OVERTIME)
            ->orderBy('id')
            ->get();

        $count = 0;
        foreach ($pending as $authorization) {
            $label = "#{$authorization->id} (empleado {$authorization->employee_id}, {$authorization->date}, {$authorization->hours}h)";

            if ($dryRun) {
                if ($controller->matchesDetectedForAutoApproval($authorization)) {
                    $this->line("[dry-run] se aprobaría {$label}");
                    $count++;
                }

                continue;
            }

            if ($controller->attemptOvertimeAutoApproval($authorization)) {
                $this->info("Aprobada {$label}");
                $count++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Calificarían: ' : 'Aprobadas: ').$count);

        return self::SUCCESS;
    }
}
