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
 * al capturarlas (regla de Luis, 2026-07-30). Las que reclaman MÁS de lo que la
 * checada demuestra se PARTEN (Elias 2026-08-05): la porción respaldada se
 * aprueba y el excedente queda pendiente marcado como extra fuera de checada.
 * Usa --dry-run para previsualizar.
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

        // TE + velada: la velada respaldada por el bloque nocturno de las
        // checadas también se auto-aprueba (Luis 2026-08-11).
        $pending = Authorization::where('status', Authorization::STATUS_PENDING)
            ->whereIn('type', [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT])
            ->orderBy('id')
            ->get();

        $count = 0;
        $splitCount = 0;
        foreach ($pending as $authorization) {
            $label = "#{$authorization->id} (empleado {$authorization->employee_id}, {$authorization->date}, {$authorization->hours}h)";

            if ($dryRun) {
                if ($controller->matchesDetectedForAutoApproval($authorization)) {
                    $this->line("[dry-run] se aprobaría {$label}");
                    $count++;
                } elseif ($split = $controller->detectUnbackedSplit($authorization)) {
                    $this->line(sprintf(
                        '[dry-run] se partiría %s: %.2fh aprobadas (%s–%s) + %.2fh a revisión como extra fuera de checada',
                        $label,
                        $split['backed_hours'],
                        $split['backed_start'],
                        $split['backed_end'],
                        $split['excess_hours'],
                    ));
                    $splitCount++;
                }

                continue;
            }

            if ($controller->attemptOvertimeAutoApproval($authorization)) {
                $this->info("Aprobada {$label}");
                $count++;
            } elseif ($split = $controller->attemptOvertimeSplitApproval($authorization)) {
                $this->info(sprintf(
                    'Partida %s: %.2fh aprobadas + %.2fh a revisión (extra fuera de checada)',
                    $label,
                    $split['backed_hours'],
                    $split['excess_hours'],
                ));
                $splitCount++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Calificarían: ' : 'Aprobadas: ').$count
            .($dryRun ? ' · Se partirían: ' : ' · Partidas: ').$splitCount);

        return self::SUCCESS;
    }
}
