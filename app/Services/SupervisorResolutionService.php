<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Position;

/**
 * Resolves supervisor assignments based on position hierarchy.
 *
 * When a position has a supervisor_position_id, this service
 * finds the active employee holding that supervisor position
 * and assigns them as the employee's supervisor.
 */
class SupervisorResolutionService
{
    /**
     * Resolve and assign the supervisor for an employee based on position hierarchy.
     *
     * Args:
     *     employee: The employee to resolve supervisor for
     *
     * Returns:
     *     The resolved supervisor Employee, or null if none found
     */
    public function resolveAndAssign(Employee $employee): ?Employee
    {
        if (! $employee->position_id) {
            return null;
        }

        $position = Position::find($employee->position_id);

        if (! $position || ! $position->supervisor_position_id) {
            return null;
        }

        // Already has a manually-set supervisor? Don't override
        if ($employee->supervisor_id) {
            return $employee->supervisor;
        }

        $supervisor = $this->findSupervisor($position->supervisor_position_id, $employee->department_id);

        if ($supervisor) {
            $employee->update(['supervisor_id' => $supervisor->id]);
        }

        return $supervisor;
    }

    /**
     * Resync supervisors for all employees holding a given position.
     *
     * Args:
     *     positionId: The position ID to resync
     */
    public function resyncAllForPosition(int $positionId): void
    {
        $employees = Employee::where('position_id', $positionId)
            ->where('status', 'active')
            ->get();

        foreach ($employees as $employee) {
            $position = $employee->position;

            if (! $position || ! $position->supervisor_position_id) {
                continue;
            }

            $supervisor = $this->findSupervisor($position->supervisor_position_id, $employee->department_id);

            if ($supervisor) {
                $employee->update(['supervisor_id' => $supervisor->id]);
            }
        }
    }

    /**
     * Find a supervisor by their position ID.
     *
     * Prefers an active employee in the same department.
     *
     * Args:
     *     supervisorPositionId: Position ID of the supervisor role
     *     preferredDepartmentId: Department ID to prefer when multiple candidates exist
     *
     * Returns:
     *     The supervisor Employee, or null if none found
     */
    private function findSupervisor(int $supervisorPositionId, ?int $preferredDepartmentId): ?Employee
    {
        $candidates = Employee::where('position_id', $supervisorPositionId)
            ->where('status', 'active')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Prefer candidate in same department
        if ($preferredDepartmentId) {
            $sameDept = $candidates->where('department_id', $preferredDepartmentId)->first();
            if ($sameDept) {
                return $sameDept;
            }
        }

        return $candidates->first();
    }
}
