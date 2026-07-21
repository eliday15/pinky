<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Trait Auditable
 *
 * Automatically logs create, update, and delete operations on models, and
 * exposes helpers so callers can record *semantic* events (approve, reject,
 * close, recalculate...) with the same "who did it" resolution.
 *
 * Models may define:
 *  - $auditModule       the module the model belongs to
 *  - $auditExcluded     fields never stored in the trail
 *  - auditSubjectLabel()  a human readable name for the affected record
 *  - auditEmployeeId()    the employee the record is about
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

        // Log deletes (soft or hard)
        static::deleted(function ($model) {
            $model->logAudit(AuditLog::ACTION_DELETE, $model->getAttributes(), null);
        });
    }

    /**
     * Create an audit log entry for this model.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function logAudit(
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?array $metadata = null,
    ): ?AuditLog {
        $sensitiveFields = $this->getAuditExcludedFields();

        if ($oldValues) {
            $oldValues = array_diff_key($oldValues, array_flip($sensitiveFields));
        }

        if ($newValues) {
            $newValues = array_diff_key($newValues, array_flip($sensitiveFields));
        }

        // A change that only touched excluded fields (e.g. a touch() that bumped
        // updated_at) is noise: it produced the empty "Cambios" panels that made
        // the trail unreadable. Skip it rather than storing a blank entry.
        if ($action === AuditLog::ACTION_UPDATE && empty($oldValues) && empty($newValues)) {
            return null;
        }

        return AuditLog::record(
            module: $this->getAuditModule(),
            action: $action,
            model: $this,
            oldValues: $oldValues ?: null,
            newValues: $newValues ?: null,
            description: $description,
            employeeId: $this->resolveAuditEmployeeId(),
            subjectLabel: $this->resolveAuditSubjectLabel(),
            metadata: $metadata,
            automatic: true,
        );
    }

    /**
     * Record a semantic event (approve, reject, close, ...) on this model.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordAuditEvent(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $module = null,
    ): ?AuditLog {
        // AuditLog::record() absorbs the generic entry the model events just
        // wrote for this record, so the trail shows one meaningful line with
        // the field diff attached rather than two entries for one action.
        return AuditLog::record(
            module: $module ?? $this->getAuditModule(),
            action: $action,
            model: $this,
            oldValues: $oldValues,
            newValues: $newValues,
            description: $description,
            employeeId: $this->resolveAuditEmployeeId(),
            subjectLabel: $this->resolveAuditSubjectLabel(),
            metadata: $metadata,
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
     *
     * @return array<int, string>
     */
    protected function getAuditExcludedFields(): array
    {
        return $this->auditExcluded ?? ['password', 'remember_token', 'created_at', 'updated_at'];
    }

    /**
     * Resolve the employee this record is about, without exploding on models
     * that have no such relationship.
     */
    protected function resolveAuditEmployeeId(): ?int
    {
        if (method_exists($this, 'auditEmployeeId')) {
            return $this->auditEmployeeId();
        }

        $value = $this->getAttribute('employee_id');

        return $value !== null ? (int) $value : null;
    }

    /**
     * Format a date attribute for a subject label, whatever its cast.
     */
    protected function auditDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a human readable label for this record.
     */
    protected function resolveAuditSubjectLabel(): ?string
    {
        try {
            if (method_exists($this, 'auditSubjectLabel')) {
                $label = $this->auditSubjectLabel();

                return $label !== null ? mb_substr($label, 0, 250) : null;
            }
        } catch (\Throwable) {
            // A label must never break the write it describes.
            return null;
        }

        return class_basename($this) . ' #' . $this->getKey();
    }
}
