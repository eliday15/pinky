<?php

namespace App\Models\Concerns;

use App\Support\MoneyVisibility;

/**
 * Quita del JSON que llega al navegador los campos de dinero del modelo (y de
 * su pivote de conceptos) cuando quien mira no puede ver dinero. Así ninguna
 * pantalla que mande el modelo completo filtra sueldos o montos por descuido.
 *
 * El modelo define MONEY_ATTRIBUTES. Solo afecta la serialización: la base de
 * datos, la auditoría (que lee los atributos crudos) y la nómina no cambian.
 */
trait HidesMoneyAttributes
{
    /** Montos que viajan en los pivotes de conceptos (empleado, puesto, depto). */
    private const MONEY_PIVOT_ATTRIBUTES = ['custom_fixed_amount', 'custom_percentage', 'default_fixed_amount', 'default_percentage'];

    public function toArray()
    {
        $array = parent::toArray();

        if (! app(MoneyVisibility::class)->hidden()) {
            return $array;
        }

        foreach (static::MONEY_ATTRIBUTES as $attribute) {
            unset($array[$attribute]);
        }
        if (isset($array['pivot']) && is_array($array['pivot'])) {
            foreach (self::MONEY_PIVOT_ATTRIBUTES as $attribute) {
                unset($array['pivot'][$attribute]);
            }
        }

        return $array;
    }
}
