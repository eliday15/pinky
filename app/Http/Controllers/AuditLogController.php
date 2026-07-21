<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Support\AuditContext;
use App\Support\AuditFieldLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for viewing audit logs.
 */
class AuditLogController extends Controller
{
    /**
     * Display a listing of audit logs.
     */
    public function index(Request $request): Response
    {
        $this->authorizeAccess();

        $query = AuditLog::with(['user:id,name', 'employee:id,full_name,employee_number'])
            ->when($request->module, fn ($q, $module) => $q->where('module', $module))
            ->when($request->action, fn ($q, $action) => $q->where('action', $action))
            ->when($request->user_id, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->employee_id, fn ($q, $employeeId) => $q->where('employee_id', $employeeId))
            ->when($request->context, fn ($q, $context) => $q->where('context', $context))
            ->when($request->entity, fn ($q, $entity) => $q->where('auditable_type', $entity))
            ->when($request->actor_role, fn ($q, $role) => $q->where('actor_role', $role))
            ->when($request->from_date, fn ($q, $fromDate) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($request->to_date, fn ($q, $toDate) => $q->whereDate('created_at', '<=', $toDate))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhere('subject_label', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%"));
                });
            });

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 50))
            ->withQueryString();

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::orderBy('full_name')->get(['id', 'full_name', 'employee_number']),
            'filters' => $request->only([
                'module', 'action', 'user_id', 'employee_id', 'context',
                'entity', 'actor_role', 'from_date', 'to_date', 'search',
            ]),
            'modules' => $this->asOptions(AuditLog::moduleLabels()),
            'actions' => $this->asOptions(AuditLog::actionLabels()),
            'contexts' => $this->contextOptions(),
            'entities' => $this->asOptions(AuditLog::entityLabels()),
            'roles' => $this->roleOptions(),
        ]);
    }

    /**
     * Display a specific audit log entry.
     */
    public function show(AuditLog $auditLog): Response
    {
        $this->authorizeAccess();

        $auditLog->load(['user:id,name', 'employee:id,full_name,employee_number']);

        return Inertia::render('AuditLogs/Show', [
            'log' => $auditLog,
            'changes' => AuditFieldLabels::diff($auditLog->old_values, $auditLog->new_values),
            'metadata' => $this->labelledMetadata($auditLog->metadata),
            // Nearby activity on the same record, so an approval can be read in
            // the context of what happened to that record before and after.
            'related' => $auditLog->auditable_type
                ? AuditLog::with('user:id,name')
                    ->where('auditable_type', $auditLog->auditable_type)
                    ->where('auditable_id', $auditLog->auditable_id)
                    ->where('id', '!=', $auditLog->id)
                    ->orderByDesc('created_at')
                    ->limit(15)
                    ->get()
                : [],
        ]);
    }

    /**
     * Guard the whole controller behind the logs permission.
     */
    private function authorizeAccess(): void
    {
        if (! Auth::user()?->hasPermissionTo('logs.view')) {
            abort(403, 'No tienes permiso para ver los logs de auditoria.');
        }
    }

    /**
     * Turn a value => label map into the {value, label} list the UI expects.
     *
     * @param  array<string, string>  $map
     * @return array<int, array{value: string, label: string}>
     */
    private function asOptions(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        usort($options, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Execution-origin filter options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function contextOptions(): array
    {
        return $this->asOptions([
            AuditContext::CONTEXT_WEB => AuditContext::contextLabel(AuditContext::CONTEXT_WEB),
            AuditContext::CONTEXT_CONSOLE => AuditContext::contextLabel(AuditContext::CONTEXT_CONSOLE),
            AuditContext::CONTEXT_SYNC => AuditContext::contextLabel(AuditContext::CONTEXT_SYNC),
            AuditContext::CONTEXT_KIOSK => AuditContext::contextLabel(AuditContext::CONTEXT_KIOSK),
            AuditContext::CONTEXT_SYSTEM => AuditContext::contextLabel(AuditContext::CONTEXT_SYSTEM),
        ]);
    }

    /**
     * Roles available as an actor filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        try {
            $roles = \Spatie\Permission\Models\Role::orderBy('name')->pluck('name');
        } catch (\Throwable) {
            return [];
        }

        return $roles->map(fn ($name) => ['value' => $name, 'label' => $name])->all();
    }

    /**
     * Present metadata with readable keys.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<int, array{label: string, value: mixed}>
     */
    private function labelledMetadata(?array $metadata): array
    {
        if (empty($metadata)) {
            return [];
        }

        $rows = [];

        foreach ($metadata as $key => $value) {
            $rows[] = [
                'label' => AuditFieldLabels::label((string) $key),
                'value' => $value,
            ];
        }

        return $rows;
    }
}
