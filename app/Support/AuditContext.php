<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves *who* is performing the current action and *from where*.
 *
 * The audit trail must answer "who approved this" even when the action runs
 * outside an HTTP request (artisan commands, the ZKTeco sync, the breakfast
 * kiosk). Those flows have no authenticated user, so callers push an explicit
 * actor onto this context instead of silently logging "Sistema".
 */
class AuditContext
{
    public const CONTEXT_WEB = 'web';

    public const CONTEXT_CONSOLE = 'console';

    public const CONTEXT_SYNC = 'sync';

    public const CONTEXT_KIOSK = 'kiosk';

    public const CONTEXT_SYSTEM = 'system';

    /**
     * Explicit actor override, set by non-HTTP flows.
     *
     * @var array{name: string, role: ?string, context: string}|null
     */
    private static ?array $override = null;

    /**
     * Run a callback with an explicit actor attached to every audit entry.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function actingAs(string $name, string $context = self::CONTEXT_SYSTEM, ?string $role = null, ?callable $callback = null)
    {
        $previous = self::$override;
        self::$override = ['name' => $name, 'role' => $role, 'context' => $context];

        if ($callback === null) {
            return null;
        }

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    /**
     * Set the actor override without a callback scope.
     */
    public static function setActor(string $name, string $context = self::CONTEXT_SYSTEM, ?string $role = null): void
    {
        self::$override = ['name' => $name, 'role' => $role, 'context' => $context];
    }

    /**
     * Clear any actor override.
     */
    public static function clear(): void
    {
        self::$override = null;
    }

    /**
     * The authenticated user, when there is one.
     */
    public static function user(): ?User
    {
        try {
            $user = Auth::user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * Snapshot of the actor's display name, stored alongside user_id so the
     * trail stays readable if the user is renamed or deleted.
     */
    public static function actorName(): ?string
    {
        if ($user = self::user()) {
            return $user->name;
        }

        if (self::$override) {
            return self::$override['name'];
        }

        return app()->runningInConsole() ? 'Sistema (proceso automatico)' : null;
    }

    /**
     * Snapshot of the actor's primary role at the time of the event.
     */
    public static function actorRole(): ?string
    {
        if ($user = self::user()) {
            try {
                return $user->getRoleNames()->first();
            } catch (\Throwable) {
                return null;
            }
        }

        return self::$override['role'] ?? null;
    }

    /**
     * Where the action originated.
     */
    public static function context(): string
    {
        if (self::$override) {
            return self::$override['context'];
        }

        // An authenticated actor means a person drove this, whatever the
        // runtime: checking runningInConsole() first would mislabel every
        // action as "console" under the test runner and queue workers.
        if (self::user()) {
            return self::CONTEXT_WEB;
        }

        return app()->runningInConsole() ? self::CONTEXT_CONSOLE : self::CONTEXT_SYSTEM;
    }

    /**
     * Human readable label for a context value.
     */
    public static function contextLabel(?string $context): string
    {
        return match ($context) {
            self::CONTEXT_WEB => 'Sistema web',
            self::CONTEXT_CONSOLE => 'Consola / tarea programada',
            self::CONTEXT_SYNC => 'Sincronizacion de relojes',
            self::CONTEXT_KIOSK => 'Kiosco',
            self::CONTEXT_SYSTEM => 'Automatico',
            default => 'Desconocido',
        };
    }
}
