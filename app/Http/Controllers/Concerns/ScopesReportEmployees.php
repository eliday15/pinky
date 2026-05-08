<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Scopes the active employee IDs visible in a report based on the
 * acting user's reports.* permissions.
 */
trait ScopesReportEmployees
{
    /**
     * IDs of active employees the current user is allowed to see in reports.
     */
    protected function scopedActiveEmployeeIds(): Collection
    {
        $user = Auth::user();

        if ($user?->hasPermissionTo('reports.view_all')) {
            return Employee::active()->pluck('id');
        }

        if ($user?->hasPermissionTo('reports.view_team')) {
            $userEmployee = $user->employee;
            if (! $userEmployee) {
                return collect();
            }

            return Employee::active()
                ->whereIn('id', $userEmployee->allSubordinateIds())
                ->pluck('id');
        }

        if ($user?->hasPermissionTo('reports.view_own')) {
            $ownId = $user->employee?->id;

            return $ownId
                ? Employee::active()->where('id', $ownId)->pluck('id')
                : collect();
        }

        return collect();
    }
}
