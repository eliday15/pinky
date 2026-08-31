<?php

namespace App\Services;

use App\Models\Authorization;
use App\Models\CompensationType;
use Illuminate\Support\Collection;

/** Fuente única de la cantidad que la empresa comprometió al aprobar. */
class ApprovedAuthorizationQuantityService
{
    public function quantity(Collection $authorizations, string $type, ?array $allowedPaymentPeriods = null, bool $excludeWeekendPullRule = false): float
    {
        $eligible = $authorizations->filter(function (Authorization $authorization) use ($type, $allowedPaymentPeriods, $excludeWeekendPullRule) {
            if ($authorization->type !== $type || ! in_array($authorization->status, [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID], true)) {
                return false;
            }
            $concept = $authorization->compensationType;
            if ($excludeWeekendPullRule && $concept?->hasWeekendPullRule()) {
                return false;
            }
            $paymentPeriod = $concept?->payment_period ?? CompensationType::PAYMENT_PERIOD_MONTHLY;

            return $allowedPaymentPeriods === null || in_array($paymentPeriod, $allowedPaymentPeriods, true);
        });

        if ($type !== Authorization::TYPE_OVERTIME) {
            return round((float) $eligible->sum(fn (Authorization $authorization) => max(0, (float) $authorization->hours)), 2);
        }

        // Dos filas respaldadas con ventanas encimadas representan el mismo
        // trabajo, no dos compromisos. Los overrides explícitos fuera de
        // checada y filas sin ventana sí son cantidades independientes.
        $total = (float) $eligible
            ->filter(fn (Authorization $a) => $a->is_unbacked_extra || ! $a->start_time || ! $a->end_time)
            ->sum(fn (Authorization $a) => max(0, (float) $a->hours));

        foreach ($eligible->reject(fn (Authorization $a) => $a->is_unbacked_extra || ! $a->start_time || ! $a->end_time)->groupBy(fn (Authorization $a) => $a->date->toDateString()) as $rows) {
            if ($rows->count() === 1) {
                $total += max(0, (float) $rows->first()->hours);
                continue;
            }
            $windows = $rows->map(function (Authorization $a) {
                $start = ((int) $a->start_time->format('H')) * 60 + (int) $a->start_time->format('i');
                $end = ((int) $a->end_time->format('H')) * 60 + (int) $a->end_time->format('i');
                if ($end <= $start) {
                    $end += 1440;
                }

                return [$start, $end, max(0, (float) $a->hours)];
            })->sortBy(fn (array $w) => $w[0])->values();
            // Integra la mayor densidad aprobada en cada segmento de la unión.
            // Así 17-19 (2 h) + 18-20 (2 h) = 3 h únicas; una cadena hasta 21
            // = 4 h; ventanas idénticas o contenidas no duplican. La densidad
            // conserva también capturas históricas donde hours no coincide con
            // la duración. La rama de una sola fila de arriba jamás se topa.
            $points = $windows->flatMap(fn (array $w) => [$w[0], $w[1]])->unique()->sort()->values()->all();
            for ($i = 0; $i < count($points) - 1; $i++) {
                $segmentStart = $points[$i];
                $segmentEnd = $points[$i + 1];
                $maxDensity = 0.0;
                foreach ($windows as [$start, $end, $hours]) {
                    if ($start < $segmentEnd && $end > $segmentStart) {
                        $maxDensity = max($maxDensity, $hours / (($end - $start) / 60));
                    }
                }
                $total += $maxDensity * (($segmentEnd - $segmentStart) / 60);
            }
        }

        return round($total, 2);
    }

    public function uniqueDates(Collection $authorizations, string $type, ?array $allowedPaymentPeriods = null): int
    {
        return $authorizations->filter(function (Authorization $authorization) use ($type, $allowedPaymentPeriods) {
            if ($authorization->type !== $type || ! in_array($authorization->status, [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID], true)) {
                return false;
            }
            $paymentPeriod = $authorization->compensationType?->payment_period ?? CompensationType::PAYMENT_PERIOD_MONTHLY;

            return $allowedPaymentPeriods === null || in_array($paymentPeriod, $allowedPaymentPeriods, true);
        })->map(fn (Authorization $authorization) => $authorization->date->toDateString())->unique()->count();
    }
}
