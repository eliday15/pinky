<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * SystemSetting model for storing application configuration.
 *
 * Provides a key-value store with type casting and caching for
 * application-wide configuration parameters.
 */
class SystemSetting extends Model
{
    use HasFactory;

    /**
     * Setting groups.
     */
    public const GROUP_GENERAL = 'general';

    public const GROUP_ATTENDANCE = 'attendance';

    public const GROUP_PAYROLL = 'payroll';

    /**
     * Cache key prefix for settings.
     */
    private const CACHE_PREFIX = 'system_settings:';

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const CACHE_TTL = 3600;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
    ];

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            if (! $setting) {
                return $default;
            }

            return $setting->getCastedValue();
        });
    }

    /**
     * Set a setting value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        $setting = self::where('key', $key)->first();

        if ($setting) {
            $setting->update(['value' => self::serializeValue($value, $setting->type)]);
        }

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Get all settings by group.
     */
    public static function getByGroup(string $group): array
    {
        return self::where('group', $group)
            ->get()
            ->mapWithKeys(fn ($setting) => [$setting->key => $setting->getCastedValue()])
            ->toArray();
    }

    /**
     * Clear all settings cache.
     */
    public static function clearCache(): void
    {
        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }

    /**
     * Get the value with proper type casting.
     */
    public function getCastedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Serialize value for storage.
     */
    private static function serializeValue(mixed $value, string $type): string
    {
        if ($type === 'json') {
            return json_encode($value);
        }

        if ($type === 'boolean') {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Scope for a specific group.
     */
    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}
