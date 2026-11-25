<?php

namespace App\Support;

use App\Models\Authorization;

/**
 * Quita el dinero de datos libres (bitácora: valores antes/después, metadatos
 * y descripciones) para quien no ve montos (semiadmin, Luis 2026-11-25).
 *
 * Las claves se reconocen por nombre; los importes escritos en texto ("$1,200")
 * se enmascaran. Es la última red: las pantallas con modelo propio ya filtran
 * en su toArray().
 */
class MoneyRedactor
{
    /** Fragmentos de clave que siempre son dinero. */
    private const MONEY_KEY_PATTERN = '/(amount|_pay$|^pay$|salary|rate|bonus|price|cost|monto|importe|clabe|bank|cash|net|gross|total|deduction|isr|imss|infonavit|subsidy|fixed|percentage|sdi|sbc|finiquito|balance|denomination|sueldo|pago|efectivo|transfer)/i';

    public const MASK = '$•••';

    public static function isMoneyKey(string $key): bool
    {
        return (bool) preg_match(self::MONEY_KEY_PATTERN, $key);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function redactArray(?array $values, ?string $auditableType = null): ?array
    {
        if ($values === null) {
            return null;
        }

        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isMoneyKey($key)) {
                continue;
            }
            // En autorizaciones la cantidad de un bono ES el dinero.
            if ($auditableType === Authorization::class && in_array($key, ['hours', 'quantity'], true)) {
                continue;
            }
            $out[$key] = is_array($value)
                ? self::redactArray($value, $auditableType)
                : (is_string($value) ? self::redactText($value) : $value);
        }

        return $out;
    }

    public static function redactText(?string $text): ?string
    {
        return $text === null ? null : preg_replace('/-?\$\s?-?[\d][\d,]*(\.\d+)?/u', self::MASK, $text);
    }
}
