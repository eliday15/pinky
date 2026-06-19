<?php

namespace App\Services;

/**
 * Computes the minimum number of MXN bills/coins to hand out.
 *
 * The Mexican denomination set is canonical, so a greedy breakdown yields the
 * minimum number of pieces. The global stock to withdraw from the bank is the
 * per-denomination sum of every employee's individual breakdown, because each
 * employee receives their exact amount (pieces are not shared between people).
 */
class CashDenominationService
{
    /**
     * Bills (1000–20) and coins (10–1), high to low.
     *
     * @var array<int,int>
     */
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    /**
     * Round a peso-and-centavos amount to the nearest whole peso.
     */
    public function roundToPeso(float $amount): int
    {
        return (int) round($amount);
    }

    /**
     * Greedy breakdown of a whole-peso amount into denomination => count.
     *
     * Zero-count denominations are omitted. Negative amounts yield an empty
     * breakdown.
     *
     * @return array<int,int>
     */
    public function breakdown(int $pesos): array
    {
        $result = [];
        $remaining = max(0, $pesos);

        foreach (self::DENOMINATIONS as $denom) {
            if ($remaining < $denom) {
                continue;
            }

            $count = intdiv($remaining, $denom);
            $result[$denom] = $count;
            $remaining -= $count * $denom;
        }

        return $result;
    }

    /**
     * Sum the individual breakdowns of many amounts into the minimum global
     * stock to withdraw.
     *
     * @param  iterable<int|float>  $amounts  Per-employee amounts (whole pesos or decimals; rounded here).
     * @return array{denominations: array<int,int>, total_pieces: int, total_amount: int}
     */
    public function breakdownGlobal(iterable $amounts): array
    {
        $denominations = [];
        $totalPieces = 0;
        $totalAmount = 0;

        foreach ($amounts as $amount) {
            $pesos = $this->roundToPeso((float) $amount);
            $totalAmount += $pesos;

            foreach ($this->breakdown($pesos) as $denom => $count) {
                $denominations[$denom] = ($denominations[$denom] ?? 0) + $count;
                $totalPieces += $count;
            }
        }

        // Keep denominations ordered high-to-low for stable presentation.
        krsort($denominations);

        return [
            'denominations' => $denominations,
            'total_pieces' => $totalPieces,
            'total_amount' => $totalAmount,
        ];
    }
}
