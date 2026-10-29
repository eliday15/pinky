<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Authorization;
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
     * Horas extra APROBADAS de empleados exentos de asistencia (no checan) en
     * un rango de fechas. Su TE no vive en attendance_records (no hay
     * checadas), así que los reportes de tiempo extra lo agregan directo desde
     * las autorizaciones — mismo criterio que la nómina. Se excluye el FIN
     * (weekend pull rule), que se paga/reporta por su propio camino.
     *
     * @param  Collection  $employeeIds  IDs ya filtrados por permisos del usuario
     * @param  string  $startDate  Y-m-d inclusive
     * @param  string  $endDate  Y-m-d inclusive
     */
    protected function exemptOvertimeAuthorizations(Collection $employeeIds, string $startDate, string $endDate): Collection
    {
        return Authorization::with(['employee.department', 'employee.compensationTypes' => fn ($q) => $q->wherePivot('is_active', true), 'compensationType'])
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('employee', fn ($q) => $q->where('is_attendance_exempt', true))
            ->whereBetween('date', [$startDate, $endDate])
            ->where('type', Authorization::TYPE_OVERTIME)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->get()
            ->reject(fn (Authorization $a) => $a->compensationType?->hasWeekendPullRule())
            ->values();
    }

    /**
     * IDs of active employees the current user is allowed to see in reports.
     */
    protected function scopedActiveEmployeeIds(): Collection
    {
        return $this->scopedEmployeeIds(fn () => Employee::active());
    }

    /**
     * Como scopedActiveEmployeeIds, más las BAJAS con fecha de baja dentro o
     * después del inicio del rango: trabajaron en él y lo que se les autorizó
     * sigue pendiente de pago (Luis 2026-10-28: Yazmín Cristóbal, baja 6/10,
     * con $608.50 de extras de septiembre que el reporte no mostraba).
     */
    protected function scopedEmployeeIdsWorkingSince(string $startDate): Collection
    {
        return $this->scopedEmployeeIds(fn () => Employee::query()->where(fn ($q) => $q
            ->where('status', 'active')
            ->orWhere(fn ($t) => $t->where('status', 'terminated')
                ->whereNotNull('termination_date')
                ->where('termination_date', '>=', $startDate))));
    }

    /**
     * @param  callable(): \Illuminate\Database\Eloquent\Builder  $base
     */
    private function scopedEmployeeIds(callable $base): Collection
    {
        $user = Auth::user();

        if ($user?->hasPermissionTo('reports.view_all')) {
            return $base()->pluck('id');
        }

        if ($user?->hasPermissionTo('reports.view_team')) {
            $userEmployee = $user->employee;
            if (! $userEmployee) {
                return collect();
            }

            return $base()
                ->whereIn('id', $userEmployee->allSubordinateIds())
                ->pluck('id');
        }

        if ($user?->hasPermissionTo('reports.view_own')) {
            $ownId = $user->employee?->id;

            return $ownId
                ? $base()->where('id', $ownId)->pluck('id')
                : collect();
        }

        return collect();
    }
}
