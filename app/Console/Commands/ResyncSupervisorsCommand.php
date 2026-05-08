<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\SupervisorResolutionService;
use Illuminate\Console\Command;

/**
 * Repair every employee's supervisor_id so the cascade hierarchy works
 * without holes: cleans orphans, fixes self-references, auto-resolves
 * missing supervisors via position hierarchy, and reports cycles.
 */
class ResyncSupervisorsCommand extends Command
{
    protected $signature = 'employees:resync-supervisors {--dry-run : Reportar sin escribir cambios}';

    protected $description = 'Sanea employees.supervisor_id: huerfanos, self-refs, auto-resuelve por puesto, reporta ciclos.';

    public function handle(SupervisorResolutionService $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo dry-run: no se escribiran cambios.');
        }

        $orphansCleaned = $this->cleanOrphans($dryRun);
        $selfRefs = $this->fixSelfReferences($dryRun);
        $autoResolved = $this->autoResolveMissing($resolver, $dryRun);
        $cycles = $this->detectCycles();

        $this->newLine();
        $this->info('Reporte:');
        $this->table(
            ['Concepto', 'Total'],
            [
                ['Huerfanos limpiados (supervisor borrado/inactivo)', $orphansCleaned],
                ['Self-references corregidos', $selfRefs],
                ['Auto-resueltos por puesto', $autoResolved],
                ['Ciclos detectados (requieren revision humana)', count($cycles)],
            ]
        );

        if (! empty($cycles)) {
            $this->newLine();
            $this->warn('Ciclos detectados — revisalos manualmente:');
            foreach ($cycles as $cycle) {
                $this->line('  ' . implode(' -> ', $cycle));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Clear supervisor_id when the supervisor is soft-deleted, terminated,
     * or simply does not exist anymore.
     */
    private function cleanOrphans(bool $dryRun): int
    {
        $count = 0;

        Employee::whereNotNull('supervisor_id')
            ->chunkById(200, function ($employees) use (&$count, $dryRun) {
                foreach ($employees as $emp) {
                    $sup = Employee::withTrashed()->find($emp->supervisor_id);
                    $invalid = $sup === null
                        || $sup->trashed()
                        || $sup->status !== 'active';

                    if ($invalid) {
                        $count++;
                        if (! $dryRun) {
                            $emp->updateQuietly(['supervisor_id' => null]);
                        }
                    }
                }
            });

        return $count;
    }

    /**
     * Set supervisor_id to null whenever an employee is their own supervisor.
     */
    private function fixSelfReferences(bool $dryRun): int
    {
        $rows = Employee::whereColumn('id', 'supervisor_id')->get();

        if (! $dryRun) {
            foreach ($rows as $emp) {
                $emp->updateQuietly(['supervisor_id' => null]);
            }
        }

        return $rows->count();
    }

    /**
     * Auto-assign supervisor for active employees whose position has a
     * supervisor_position_id and who currently lack a supervisor.
     */
    private function autoResolveMissing(SupervisorResolutionService $resolver, bool $dryRun): int
    {
        $count = 0;

        $candidates = Employee::active()
            ->whereNull('supervisor_id')
            ->whereHas('position', fn ($q) => $q->whereNotNull('supervisor_position_id'))
            ->get();

        foreach ($candidates as $emp) {
            if ($dryRun) {
                $position = $emp->position;
                if ($position && $position->supervisor_position_id) {
                    $count++;
                }

                continue;
            }

            $resolved = $resolver->resolveAndAssign($emp);
            if ($resolved) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Walk supervisor_id chains until null or repeat. Return the cycle
     * sequence by employee_number for any chain that loops back to itself.
     */
    private function detectCycles(): array
    {
        $cycles = [];
        $globalSeen = [];
        $employees = Employee::whereNotNull('supervisor_id')->get(['id', 'supervisor_id', 'employee_number', 'full_name']);
        $byId = $employees->keyBy('id');

        foreach ($employees as $start) {
            if (isset($globalSeen[$start->id])) {
                continue;
            }

            $path = [];
            $pathIndex = [];
            $currentId = $start->id;

            while ($currentId !== null && ! isset($pathIndex[$currentId])) {
                $pathIndex[$currentId] = count($path);
                $path[] = $currentId;
                $globalSeen[$currentId] = true;
                $currentId = $byId[$currentId]->supervisor_id ?? null;
            }

            if ($currentId !== null && isset($pathIndex[$currentId])) {
                $cycleIds = array_slice($path, $pathIndex[$currentId]);
                $cycleIds[] = $currentId;
                $cycles[] = array_map(
                    fn ($id) => ($byId[$id]->employee_number ?? '?') . ' (' . ($byId[$id]->full_name ?? '?') . ')',
                    $cycleIds
                );
            }
        }

        return $cycles;
    }
}
