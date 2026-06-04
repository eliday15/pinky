<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\LateAbsenceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Cierre mensual de la regla retardos→falta.
 *
 * Genera las incidencias FRT auto-aprobadas del mes cerrado para todos los
 * empleados activos. Es idempotente (un mes ya procesado no se repite) y
 * además autocurable: PayrollCalculatorService también garantiza la
 * generación al calcular, así que este comando aporta puntualidad y
 * visibilidad, no correctitud.
 */
class CloseMonthlyLateAbsences extends Command
{
    protected $signature = 'late-absences:close
        {--month= : Mes a cerrar (YYYY-MM); por defecto el mes anterior}
        {--dry-run : Solo muestra qué se generaría, sin escribir}';

    protected $description = 'Genera las incidencias FRT (faltas por retardos acumulados) del mes cerrado';

    public function handle(LateAbsenceService $service): int
    {
        $monthOption = $this->option('month');

        if ($monthOption !== null && ! preg_match('/^\d{4}-\d{2}$/', $monthOption)) {
            $this->error('El mes debe tener formato YYYY-MM.');

            return self::FAILURE;
        }

        $month = $monthOption
            ? Carbon::createFromFormat('Y-m-d', $monthOption.'-01')->startOfMonth()
            : Carbon::today()->startOfMonth()->subMonthNoOverflow();

        $startMonth = $service->startMonth();

        if (! $startMonth) {
            $this->error('El setting monthly_late_absence_start_month no está configurado; la regla mensual está inactiva.');

            return self::FAILURE;
        }

        if ($month->lt($startMonth)) {
            $this->warn("El mes {$month->format('Y-m')} es anterior al corte de la regla ({$startMonth->format('Y-m')}); no se procesa.");

            return self::SUCCESS;
        }

        if ($month->copy()->endOfMonth()->gte(Carbon::today()->startOfDay())) {
            $this->warn("El mes {$month->format('Y-m')} aún no termina; no se procesa.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $threshold = $service->threshold();
        $rows = [];
        $created = 0;

        foreach (Employee::active()->orderBy('id')->get() as $employee) {
            $lateCount = $service->lateCountForMonth($employee, $month);
            $absences = $service->absencesFromLates($lateCount);

            if ($absences < 1) {
                continue;
            }

            if ($dryRun) {
                $rows[] = [$employee->employee_number, $employee->full_name, $lateCount, $absences, 'dry-run'];

                continue;
            }

            $incident = $service->generateForMonth($employee, $month);

            if ($incident !== null) {
                $created++;
                $rows[] = [$employee->employee_number, $employee->full_name, $lateCount, $absences, "incidencia #{$incident->id}"];
            } else {
                $rows[] = [$employee->employee_number, $employee->full_name, $lateCount, $absences, 'ya procesado'];
            }
        }

        $this->info("Cierre de retardos {$month->format('Y-m')} (umbral: {$threshold}).");

        if ($rows === []) {
            $this->info('Ningún empleado alcanzó el umbral de retardos.');
        } else {
            $this->table(['No. Empleado', 'Empleado', 'Retardos', 'Faltas', 'Resultado'], $rows);
        }

        if (! $dryRun) {
            $this->info("Incidencias FRT creadas: {$created}.");
        }

        return self::SUCCESS;
    }
}
