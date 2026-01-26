<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Trait Auditable
 *
 * Automatically logs create, update, and delete operations on models.
 * Add this trait to any model that should be audited.
 *
 * Requires defining $auditModule on the model to specify the module name.
 */
trait Auditable
{
    /**
     * Boot the auditable trait.
     */
    public static function bootAuditable(): void
    {
        // Log creation
        static::created(function ($model) {
            $model->logAudit(AuditLog::ACTION_CREATE, null, $model->getAttributes());
        });

        // Log updates
        static::updated(function ($model) {
            $dirty = $model->getDirty();
            if (! empty($dirty)) {
                $original = array_intersect_key($model->getOriginal(), $dirty);
                $model->logAudit(AuditLog::ACTION_UPDATE, $original, $dirty);
            }
        });

        // Log soft deletes
        static::deleted(function ($model) {
            $action = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()
                ? AuditLog::ACTION_DELETE
                : AuditLog::ACTION_DELETE;
            $model->logAudit($action, $model->getAttributes(), null);
        });
    }

    /**
     * Create an audit log entry for this model.
     */
    public function logAudit(string $action, ?array $oldValues = null, ?array $newValues = null, ?string $description = null): void
    {
        // Filter out sensitive fields
        $sensitiveFields = $this->getAuditExcludedFields();

        if ($oldValues) {
            $oldValues = array_diff_key($oldValues, array_flip($sensitiveFields));
        }

        if ($newValues) {
            $newValues = array_diff_key($newValues, array_flip($sensitiveFields));
        }

        AuditLog::log(
            $this->getAuditModule(),
            $action,
            $this,
            $oldValues,
            $newValues,
            $description
        );
    }

    /**
     * Get the module name for audit logging.
     */
    protected function getAuditModule(): string
    {
        return $this->auditModule ?? strtolower(class_basename($this)) . 's';
    }

    /**
     * Get fields that should be excluded from audit logs.
     */
    protected function getAuditExcludedFields(): array
    {
        return $this->auditExcluded ?? ['password', 'remember_token', 'created_at', 'updated_at'];
    }
}
