<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * AuditLog model for tracking all changes to critical models.
 *
 * Provides a complete audit trail for security and compliance purposes.
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

    protected $fillable = [
        'user_id',
        'module',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'description',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the auditable model.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an audit log entry.
     */
    public static function log(
        string $module,
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): self {
        return self::create([
            'user_id' => auth()->id(),
            'module' => $module,
            'action' => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id' => $model?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'description' => $description,
        ]);
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
     * Scope for date range.
     */
    public function scopeDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Get human-readable module name.
     */
    public function getModuleNameAttribute(): string
    {
        return match ($this->module) {
            self::MODULE_EMPLOYEES => 'Empleados',
            self::MODULE_ATTENDANCE => 'Asistencia',
            self::MODULE_PAYROLL => 'Nomina',
            self::MODULE_INCIDENTS => 'Incidencias',
            self::MODULE_AUTHORIZATIONS => 'Autorizaciones',
            self::MODULE_SETTINGS => 'Configuracion',
            self::MODULE_AUTH => 'Autenticacion',
            default => $this->module,
        };
    }

    /**
     * Get human-readable action name.
     */
    public function getActionNameAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'Crear',
            self::ACTION_UPDATE => 'Actualizar',
            self::ACTION_DELETE => 'Eliminar',
            self::ACTION_APPROVE => 'Aprobar',
            self::ACTION_REJECT => 'Rechazar',
            self::ACTION_LOGIN => 'Iniciar sesion',
            self::ACTION_LOGOUT => 'Cerrar sesion',
            self::ACTION_SYNC => 'Sincronizar',
            self::ACTION_EXPORT => 'Exportar',
            default => $this->action,
        };
    }

    /**
     * Get action color for UI.
     */
    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'green',
            self::ACTION_UPDATE => 'blue',
            self::ACTION_DELETE => 'red',
            self::ACTION_APPROVE => 'green',
            self::ACTION_REJECT => 'red',
            self::ACTION_LOGIN => 'indigo',
            self::ACTION_LOGOUT => 'gray',
            self::ACTION_SYNC => 'purple',
            self::ACTION_EXPORT => 'yellow',
            default => 'gray',
        };
    }
}
