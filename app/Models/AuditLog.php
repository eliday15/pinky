<?php

namespace App\Models;

use App\Support\AuditContext;
use App\Support\AuditEntryRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;

/**
 * AuditLog model for tracking all changes to critical models.
 *
 * Provides a complete audit trail for security and compliance purposes:
 * every entry records who acted (user id plus a name/role snapshot that
 * survives user deletion), from where (web, console, sync, kiosk), on what
 * (model plus the affected employee) and what changed.
 */
class AuditLog extends Model
{
    use HasFactory;

    /**
     * Modules that can be audited.
     */
    public const MODULE_EMPLOYEES = 'employees';

    public const MODULE_ATTENDANCE = 'attendance';

    public const MODULE_PAYROLL = 'payroll';

    public const MODULE_INCIDENTS = 'incidents';

    public const MODULE_AUTHORIZATIONS = 'authorizations';

    public const MODULE_SETTINGS = 'settings';

    public const MODULE_AUTH = 'auth';

    public const MODULE_USERS = 'users';

    public const MODULE_SCHEDULES = 'schedules';

    // Keeps the historical value already stored on CheckOmission rows.
    public const MODULE_OMISSIONS = 'check_omissions';

    public const MODULE_DEPARTMENTS = 'departments';

    public const MODULE_POSITIONS = 'positions';

    public const MODULE_COMPENSATION_TYPES = 'compensation_types';

    public const MODULE_BREAKFASTS = 'breakfasts';

    public const MODULE_VACATIONS = 'vacations';

    public const MODULE_CASH = 'cash';

    public const MODULE_ANOMALIES = 'anomalies';

    public const MODULE_CATALOGS = 'catalogs';

    public const MODULE_REPORTS = 'reports';

    /**
     * Actions that can be audited.
     */
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_DELETE = 'delete';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGOUT = 'logout';

    public const ACTION_SYNC = 'sync';

    public const ACTION_EXPORT = 'export';

    public const ACTION_CANCEL = 'cancel';

    public const ACTION_RECALCULATE = 'recalculate';

    public const ACTION_CLOSE = 'close';

    public const ACTION_REOPEN = 'reopen';

    public const ACTION_PAY = 'pay';

    public const ACTION_IMPORT = 'import';

    public const ACTION_RESOLVE = 'resolve';

    public const ACTION_LOGIN_FAILED = 'login_failed';

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_role',
        'context',
        'module',
        'action',
        'auditable_type',
        'auditable_id',
        'employee_id',
        'subject_label',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'description',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    protected $appends = [
        'actor_label',
        'context_label',
        'summary',
        'entity_label',
    ];

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the employee this entry is about, when applicable.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the auditable model.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Canonical writer. Resolves the actor and context automatically.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public static function record(
        string $module,
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $employeeId = null,
        ?string $subjectLabel = null,
        ?array $metadata = null,
        bool $automatic = false,
    ): ?self {
        // A semantic event (approve, close, ...) absorbs the generic entry the
        // model events just wrote for the same record, so one business action
        // leaves one readable line carrying the field diff.
        if (! $automatic && $model !== null && self::isAuditable($model)) {
            $absorbed = AuditEntryRegistry::absorb($model);
            $oldValues ??= $absorbed['old'];
            $newValues ??= $absorbed['new'];
        }

        try {
            $entry = self::write(
                $module, $action, $model, $oldValues, $newValues,
                $description, $employeeId, $subjectLabel, $metadata,
            );
        } catch (\Throwable $e) {
            // The trail must never be able to roll back the thing it records:
            // a payroll approval cannot fail because logging it failed. This
            // also keeps data migrations working before the table is enriched.
            Log::warning('No se pudo escribir el log de auditoria', [
                'module' => $module,
                'action' => $action,
                'auditable_type' => $model ? get_class($model) : null,
                'auditable_id' => $model?->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($automatic && $model !== null) {
            AuditEntryRegistry::remember($model, $entry);
        }

        return $entry;
    }

    /**
     * Perform the actual insert.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    private static function write(
        string $module,
        string $action,
        ?Model $model,
        ?array $oldValues,
        ?array $newValues,
        ?string $description,
        ?int $employeeId,
        ?string $subjectLabel,
        ?array $metadata,
    ): self {
        return self::create([
            'user_id' => AuditContext::user()?->id,
            'actor_name' => AuditContext::actorName(),
            'actor_role' => AuditContext::actorRole(),
            'context' => AuditContext::context(),
            'module' => $module,
            'action' => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id' => $model?->getKey(),
            'employee_id' => $employeeId,
            'subject_label' => $subjectLabel,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'ip_address' => self::currentIp(),
            'user_agent' => self::currentUserAgent(),
            'description' => $description,
        ]);
    }

    /**
     * Whether a model participates in the automatic-entry registry.
     */
    private static function isAuditable(Model $model): bool
    {
        return in_array(\App\Traits\Auditable::class, class_uses_recursive($model), true);
    }

    /**
     * Backwards-compatible alias for record().
     */
    public static function log(
        string $module,
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): ?self {
        return self::record($module, $action, $model, $oldValues, $newValues, $description);
    }

    /**
     * Request IP, or null outside an HTTP request.
     */
    private static function currentIp(): ?string
    {
        // A console run has no client IP: reading one yields a meaningless
        // 127.0.0.1, which is what made sync entries look like local edits.
        if (! self::hasClientContext()) {
            return null;
        }

        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the current action came from a real client request.
     */
    private static function hasClientContext(): bool
    {
        return in_array(
            AuditContext::context(),
            [AuditContext::CONTEXT_WEB, AuditContext::CONTEXT_KIOSK],
            true,
        );
    }

    /**
     * Request user agent, or null outside an HTTP request.
     */
    private static function currentUserAgent(): ?string
    {
        if (! self::hasClientContext()) {
            return null;
        }

        try {
            return request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Scope for a specific module.
     */
    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope for a specific action.
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for a specific user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for entries about a specific employee.
     */
    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Scope for date range.
     */
    public function scopeDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Who performed the action, always readable.
     */
    public function getActorLabelAttribute(): string
    {
        if ($this->actor_name) {
            return $this->actor_name;
        }

        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->name;
        }

        return match ($this->context) {
            AuditContext::CONTEXT_SYNC => 'Sincronizacion automatica',
            AuditContext::CONTEXT_CONSOLE => 'Proceso automatico',
            AuditContext::CONTEXT_KIOSK => 'Kiosco',
            default => 'Sistema',
        };
    }

    /**
     * Human readable execution context.
     */
    public function getContextLabelAttribute(): string
    {
        return AuditContext::contextLabel($this->context);
    }

    /**
     * Spanish name of the affected entity type.
     */
    public function getEntityLabelAttribute(): string
    {
        if (! $this->auditable_type) {
            return '-';
        }

        return self::entityLabels()[$this->auditable_type]
            ?? class_basename($this->auditable_type);
    }

    /**
     * One sentence answering "who did what". Falls back to a generated
     * sentence so legacy rows written before descriptions existed are still
     * readable instead of showing a bare "Model #123".
     */
    public function getSummaryAttribute(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $verb = match ($this->action) {
            self::ACTION_CREATE => 'Creo',
            self::ACTION_UPDATE => 'Modifico',
            self::ACTION_DELETE => 'Elimino',
            self::ACTION_APPROVE => 'Aprobo',
            self::ACTION_REJECT => 'Rechazo',
            self::ACTION_CANCEL => 'Cancelo',
            self::ACTION_RECALCULATE => 'Recalculo',
            self::ACTION_CLOSE => 'Cerro',
            self::ACTION_REOPEN => 'Reabrio',
            self::ACTION_PAY => 'Pago',
            self::ACTION_IMPORT => 'Importo',
            self::ACTION_RESOLVE => 'Resolvio',
            self::ACTION_EXPORT => 'Exporto',
            self::ACTION_SYNC => 'Sincronizo',
            self::ACTION_LOGIN => 'Inicio sesion',
            self::ACTION_LOGOUT => 'Cerro sesion',
            self::ACTION_LOGIN_FAILED => 'Intento iniciar sesion sin exito',
            default => $this->action,
        };

        if (in_array($this->action, [self::ACTION_LOGIN, self::ACTION_LOGOUT, self::ACTION_LOGIN_FAILED], true)) {
            return $verb;
        }

        $subject = $this->subject_label
            ?: trim($this->entity_label . ($this->auditable_id ? " #{$this->auditable_id}" : ''));

        return trim("{$verb} {$subject}");
    }

    /**
     * Auditable model class to Spanish entity name.
     *
     * @return array<class-string, string>
     */
    public static function entityLabels(): array
    {
        return [
            Employee::class => 'Empleado',
            Authorization::class => 'Autorizacion',
            Incident::class => 'Incidencia',
            IncidentType::class => 'Tipo de incidencia',
            CheckOmission::class => 'Omision de checada',
            AttendanceRecord::class => 'Registro de asistencia',
            AttendanceAnomaly::class => 'Anomalia de asistencia',
            PayrollPeriod::class => 'Periodo de nomina',
            PayrollEntry::class => 'Recibo de nomina',
            CashPayout::class => 'Cobro en efectivo',
            BreakfastClaim::class => 'Desayuno',
            CompensationType::class => 'Concepto de pago',
            Department::class => 'Departamento',
            Position::class => 'Puesto',
            Schedule::class => 'Horario',
            User::class => 'Usuario',
            VacationTable::class => 'Tabla de vacaciones',
            SystemSetting::class => 'Configuracion',
        ];
    }

    /**
     * All module values with their Spanish labels.
     *
     * @return array<string, string>
     */
    public static function moduleLabels(): array
    {
        return [
            self::MODULE_EMPLOYEES => 'Empleados',
            self::MODULE_ATTENDANCE => 'Asistencia',
            self::MODULE_PAYROLL => 'Nomina',
            self::MODULE_INCIDENTS => 'Incidencias',
            self::MODULE_AUTHORIZATIONS => 'Autorizaciones',
            self::MODULE_SETTINGS => 'Configuracion',
            self::MODULE_AUTH => 'Autenticacion',
            self::MODULE_USERS => 'Usuarios y permisos',
            self::MODULE_SCHEDULES => 'Horarios',
            self::MODULE_OMISSIONS => 'Omisiones de checada',
            self::MODULE_DEPARTMENTS => 'Departamentos',
            self::MODULE_POSITIONS => 'Puestos',
            self::MODULE_COMPENSATION_TYPES => 'Conceptos de pago',
            self::MODULE_BREAKFASTS => 'Desayunos',
            self::MODULE_VACATIONS => 'Vacaciones',
            self::MODULE_CASH => 'Pagos en efectivo',
            self::MODULE_ANOMALIES => 'Anomalias',
            self::MODULE_CATALOGS => 'Catalogos',
            self::MODULE_REPORTS => 'Reportes',
        ];
    }

    /**
     * All action values with their Spanish labels.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            self::ACTION_CREATE => 'Crear',
            self::ACTION_UPDATE => 'Actualizar',
            self::ACTION_DELETE => 'Eliminar',
            self::ACTION_APPROVE => 'Aprobar',
            self::ACTION_REJECT => 'Rechazar',
            self::ACTION_CANCEL => 'Cancelar',
            self::ACTION_RECALCULATE => 'Recalcular',
            self::ACTION_CLOSE => 'Cerrar',
            self::ACTION_REOPEN => 'Reabrir',
            self::ACTION_PAY => 'Pagar',
            self::ACTION_IMPORT => 'Importar',
            self::ACTION_RESOLVE => 'Resolver',
            self::ACTION_LOGIN => 'Iniciar sesion',
            self::ACTION_LOGOUT => 'Cerrar sesion',
            self::ACTION_LOGIN_FAILED => 'Intento fallido de sesion',
            self::ACTION_SYNC => 'Sincronizar',
            self::ACTION_EXPORT => 'Exportar',
        ];
    }

    /**
     * Get human-readable module name.
     */
    public function getModuleNameAttribute(): string
    {
        return self::moduleLabels()[$this->module] ?? $this->module;
    }

    /**
     * Get human-readable action name.
     */
    public function getActionNameAttribute(): string
    {
        return self::actionLabels()[$this->action] ?? $this->action;
    }

    /**
     * Get action color for UI.
     */
    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE, self::ACTION_APPROVE => 'green',
            self::ACTION_UPDATE, self::ACTION_RECALCULATE => 'blue',
            self::ACTION_DELETE, self::ACTION_REJECT, self::ACTION_CANCEL, self::ACTION_LOGIN_FAILED => 'red',
            self::ACTION_LOGIN => 'indigo',
            self::ACTION_LOGOUT => 'gray',
            self::ACTION_SYNC, self::ACTION_IMPORT => 'purple',
            self::ACTION_EXPORT => 'yellow',
            self::ACTION_CLOSE, self::ACTION_PAY => 'emerald',
            self::ACTION_REOPEN, self::ACTION_RESOLVE => 'amber',
            default => 'gray',
        };
    }
}
