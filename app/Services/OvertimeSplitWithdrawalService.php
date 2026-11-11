<?php

namespace App\Services;

use App\Models\Authorization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Captura de TE PARTIDA: la porción que respalda la checada se aprueba sola y
 * el excedente queda pendiente como "extra fuera de checada", ligado a ella
 * por generated_from_authorization_id.
 *
 * Caso Dani 2026-11-11 (Luis Ortega 5/11, #11994/#11995): la captura venía con
 * fecha equivocada; quien capturó borró el excedente pensando que retiraba la
 * captura, y la parte aprobada (8:27–9:00, su llegada antes del turno) siguió
 * viva y se pagaba como si el sistema la hubiera inventado. Este servicio
 * identifica esa parte aprobada para avisarlo y permitir retirarla en el
 * mismo paso.
 */
class OvertimeSplitWithdrawalService
{
    /**
     * Parte aprobada de la captura de la que salió este excedente.
     *
     * Args:
     *     excess: Autorización que podría ser el excedente de un split.
     *
     * Returns:
     *     La autorización aprobada (no pagada) de la misma captura, o null si
     *     no es un excedente de TE partido o la otra parte ya no está aprobada.
     */
    public function backedPartOf(Authorization $excess): ?Authorization
    {
        if (! $excess->is_unbacked_extra
            || $excess->type !== Authorization::TYPE_OVERTIME
            || ! $excess->generated_from_authorization_id) {
            return null;
        }

        $parent = Authorization::find($excess->generated_from_authorization_id);
        if (! $parent
            || $parent->type !== Authorization::TYPE_OVERTIME
            || $parent->is_unbacked_extra
            || ! $parent->isApproved()) {
            return null;
        }

        return $parent;
    }

    /**
     * ¿Puede este usuario retirar la parte aprobada?
     *
     * Quien tiene permiso de rechazarla, sí. Además, quien CAPTURÓ la captura
     * puede retirar lo que se aprobó solo a partir de ella (no la aprobó un
     * humano), siempre que la nómina que la paga no esté aprobada.
     */
    public function canWithdraw(User $user, Authorization $parent): bool
    {
        if (Gate::forUser($user)->allows('reject', $parent)) {
            return true;
        }

        return $parent->requested_by === $user->id
            && $parent->approved_by === $user->id
            && ! $parent->isPaid()
            && ($user->hasRole('superadmin') || ! $parent->isPaymentLockedByPeriod());
    }

    /**
     * Descripción corta para avisos: "8:27 a 9:00 (0.5 h, #11994)".
     */
    public function describe(Authorization $parent): string
    {
        $fmt = fn ($t) => $t ? Carbon::parse($t)->format('G:i') : '?';

        return $fmt($parent->start_time).' a '.$fmt($parent->end_time)
            .' ('.rtrim(rtrim(number_format((float) $parent->hours, 2), '0'), '.').' h, #'.$parent->id.')';
    }
}
