<?php

namespace App\Support;

/**
 * ¿El usuario actual puede ver dinero (sueldos, tarifas, montos)?
 *
 * Rol SEMIADMIN (Luis 2026-11-25): autoriza lo humano pero "nada de dinero".
 * Ver dinero exige el permiso money.view, que tienen todos los roles que ya
 * existían; un rol nuevo nace SIN él (seguro por omisión). Se resuelve una
 * vez por petición.
 */
class MoneyVisibility
{
    /** @var array<int, bool> */
    private array $hiddenFor = [];

    public function hidden(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $this->hiddenFor[$user->getKey()] ??= ! $user->canSeeMoney();
    }

    public function visible(): bool
    {
        return ! $this->hidden();
    }
}
