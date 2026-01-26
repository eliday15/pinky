<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
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
        $user = Auth::user();

        if (! $user->hasPermissionTo('logs.view')) {
            abort(403, 'No tienes permiso para ver los logs de auditoria.');
        }

        $query = AuditLog::with('user')
            ->when($request->module, function ($q, $module) {
                $q->where('module', $module);
            })
            ->when($request->action, function ($q, $action) {
                $q->where('action', $action);
            })
            ->when($request->user_id, function ($q, $userId) {
                $q->where('user_id', $userId);
            })
            ->when($request->from_date, function ($q, $fromDate) {
                $q->whereDate('created_at', '>=', $fromDate);
            })
            ->when($request->to_date, function ($q, $toDate) {
                $q->whereDate('created_at', '<=', $toDate);
            })
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($u) use ($search) {
                            $u->where('name', 'like', "%{$search}%");
                        });
                });
            });

        $logs = $query->orderBy('created_at', 'desc')->paginate(50)->withQueryString();

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['module', 'action', 'user_id', 'from_date', 'to_date', 'search']),
            'modules' => $this->getModules(),
            'actions' => $this->getActions(),
        ]);
    }

    /**
     * Display a specific audit log entry.
     */
    public function show(AuditLog $auditLog): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('logs.view')) {
            abort(403, 'No tienes permiso para ver los logs de auditoria.');
        }

        $auditLog->load('user');

        return Inertia::render('AuditLogs/Show', [
            'log' => $auditLog,
        ]);
    }

    /**
     * Get available modules.
     */
    private function getModules(): array
    {
        return [
            ['value' => AuditLog::MODULE_EMPLOYEES, 'label' => 'Empleados'],
            ['value' => AuditLog::MODULE_ATTENDANCE, 'label' => 'Asistencia'],
            ['value' => AuditLog::MODULE_PAYROLL, 'label' => 'Nomina'],
            ['value' => AuditLog::MODULE_INCIDENTS, 'label' => 'Incidencias'],
            ['value' => AuditLog::MODULE_AUTHORIZATIONS, 'label' => 'Autorizaciones'],
            ['value' => AuditLog::MODULE_SETTINGS, 'label' => 'Configuracion'],
            ['value' => AuditLog::MODULE_AUTH, 'label' => 'Autenticacion'],
        ];
    }

    /**
     * Get available actions.
     */
    private function getActions(): array
    {
        return [
            ['value' => AuditLog::ACTION_CREATE, 'label' => 'Crear'],
            ['value' => AuditLog::ACTION_UPDATE, 'label' => 'Actualizar'],
            ['value' => AuditLog::ACTION_DELETE, 'label' => 'Eliminar'],
            ['value' => AuditLog::ACTION_APPROVE, 'label' => 'Aprobar'],
            ['value' => AuditLog::ACTION_REJECT, 'label' => 'Rechazar'],
            ['value' => AuditLog::ACTION_LOGIN, 'label' => 'Iniciar sesion'],
            ['value' => AuditLog::ACTION_LOGOUT, 'label' => 'Cerrar sesion'],
            ['value' => AuditLog::ACTION_SYNC, 'label' => 'Sincronizar'],
            ['value' => AuditLog::ACTION_EXPORT, 'label' => 'Exportar'],
        ];
    }
}
