<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Services\ZktecoSyncService;
use Illuminate\Console\Command;

/**
 * Command to recalculate attendance metrics.
 *
 * Can be run with date range options to recalculate specific periods,
 * and can optionally reprocess from raw_punches to apply new algorithms.
 */
class RecalculateAttendanceMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:recalculate
        {--from= : Start date (YYYY-MM-DD), defaults to 30 days ago}
        {--to= : End date (YYYY-MM-DD), defaults to today}
        {--employee= : Specific employee ID to recalculate}
        {--reprocess : Reprocess from raw_punches (applies new duplicate filtering and lunch detection)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate attendance metrics for records, optionally reprocessing from raw punches';

    /**
     * Execute the console command.
     */
    public function handle(ZktecoSyncService $syncService): int
    {
        $from = $this->option('from') ?? now()->subDays(30)->toDateString();
        $to = $this->option('to') ?? now()->toDateString();
        $reprocess = $this->option('reprocess');

        $query = AttendanceRecord::whereBetween('work_date', [$from, $to])
            ->whereNotNull('check_in');

        if ($employeeId = $this->option('employee')) {
            $query->where('employee_id', $employeeId);
        }

        // If reprocessing, we need raw_punches
        if ($reprocess) {
            $query->whereNotNull('raw_punches');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn("No se encontraron registros para recalcular en el rango {$from} a {$to}.");
            return Command::SUCCESS;
        }

        $this->info("Recalculando {$total} registros del {$from} al {$to}...");
        if ($reprocess) {
            $this->info("Modo: Reprocesando desde raw_punches (aplicando nuevos algoritmos)");
        } else {
            $this->info("Modo: Recalculando metricas solamente");
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $errors = 0;

        $query->with('employee.schedule')->chunk(100, function ($records) use ($syncService, $reprocess, &$updated, &$errors, $bar) {
            foreach ($records as $record) {
                try {
                    if ($reprocess && !empty($record->raw_punches)) {
                        // Reprocess from raw punches (applies new algorithms)
                        $syncService->reprocessAttendanceRecord($record, $record->raw_punches);
                    } else {
                        // Just recalculate metrics
                        $syncService->recalculateAttendanceRecord($record);
                    }
                    $updated++;
                } catch (\Exception $e) {
                    $errors++;
                    // Continue with other records
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Recalculados {$updated} registros.");
        if ($errors > 0) {
            $this->warn("Errores: {$errors}");
        }

        return Command::SUCCESS;
    }
}
