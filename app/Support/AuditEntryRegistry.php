<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks the audit entries written automatically by model events during the
 * current request.
 *
 * When a controller records a *semantic* event for the same record (approve,
 * reject, close, recalculate...), that event absorbs the automatic entry's
 * field diff and removes it. One business action therefore produces exactly
 * one readable log line — "Aprobo Tiempo Extra de Juan Perez", with the
 * pending -> approved diff attached — instead of a generic "Modifico
 * Autorizacion #123" followed by the real event.
 */
class AuditEntryRegistry
{
    /**
     * Model key => id of the automatic audit entry written for it.
     *
     * @var array<string, int>
     */
    private static array $entries = [];

    /**
     * Remember the automatic entry written for a model.
     */
    public static function remember(Model $model, AuditLog $entry): void
    {
        self::$entries[self::key($model)] = $entry->id;
    }

    /**
     * Take the values of a model's pending automatic entry and delete it.
     *
     * @return array{old: array<string, mixed>|null, new: array<string, mixed>|null}
     */
    public static function absorb(Model $model): array
    {
        $key = self::key($model);

        if (! isset(self::$entries[$key])) {
            return ['old' => null, 'new' => null];
        }

        $entryId = self::$entries[$key];
        unset(self::$entries[$key]);

        $entry = AuditLog::find($entryId);

        if (! $entry) {
            return ['old' => null, 'new' => null];
        }

        $values = ['old' => $entry->old_values, 'new' => $entry->new_values];
        $entry->delete();

        return $values;
    }

    /**
     * Forget everything tracked so far. Used between tests.
     */
    public static function flush(): void
    {
        self::$entries = [];
    }

    /**
     * Identify a model instance.
     */
    private static function key(Model $model): string
    {
        return $model::class . ':' . $model->getKey();
    }
}
