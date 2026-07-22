<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Computes the monthly maquila/production bonus quantities from the on-prem
 * `basemaquila` SQL Server (reached via the `basemaquila` dblib connection).
 *
 * These are the auto-filled quantities behind the 5 payroll bonuses; the
 * per-unit cost lives in each CompensationType. Every formula excludes
 * cancelled rows and was verified against Pinky's own program to the unit
 * (e.g. June 2026: mandada 328,231, recibida 398,118).
 *
 * Cancel status spelling differs by table: the maquila tables use
 * `tipo='CANCELADA'` (feminine, "orden cancelada"); the cut/combination tables
 * use `tipo='CANCELADO'`.
 */
class MaquilaBonusMetricsService
{
    /**
     * Compensation type codes for the 5 maquila bonuses. The value is the
     * canonical concept code seeded in `compensation_types.code`.
     */
    public const CODE_MAQUILA_MANDADA = 'MAQ_MANDADA';

    public const CODE_MAQUILA_RECIBIDA = 'MAQ_RECIBIDA';

    public const CODE_ORDENES_CORTE = 'ORD_CORTE';

    public const CODE_ORDENES_FUSION = 'ORD_FUSION';

    public const CODE_ORDENES_BANDERAS = 'ORD_BANDERAS';

    public const CODE_ORDENES_CORTADAS = 'ORD_CORTADAS';

    /**
     * Human labels + descriptions for each bonus code (used by the seeder and UI).
     *
     * @return array<string, array{name: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            self::CODE_MAQUILA_MANDADA => [
                'name' => 'Maquila mandada',
                'description' => 'Piezas mandadas a maquila en el mes (SUM cantidad_mandada, proceso MAQUILA, sin canceladas).',
            ],
            self::CODE_MAQUILA_RECIBIDA => [
                'name' => 'Maquila recibida',
                'description' => 'Piezas recibidas de maquila en el mes (SUM cantidad_recibida, proceso MAQUILA, sin canceladas).',
            ],
            self::CODE_ORDENES_CORTE => [
                'name' => 'Órdenes de corte',
                'description' => 'Número de órdenes de corte del mes (COUNT corte_alta, sin canceladas).',
            ],
            self::CODE_ORDENES_FUSION => [
                'name' => 'Órdenes de fusión',
                'description' => 'Número de órdenes de fusión del mes (COUNT combinacion_alta con folio F y cortador2 asignado, sin canceladas).',
            ],
            self::CODE_ORDENES_BANDERAS => [
                'name' => 'Órdenes de banderas',
                'description' => 'Número de órdenes de banderas del mes (COUNT corte_alta, sin canceladas).',
            ],
            self::CODE_ORDENES_CORTADAS => [
                'name' => 'Órdenes cortadas',
                'description' => 'Número de órdenes de corte del mes con cortador2 asignado (COUNT corte_alta, sin canceladas).',
            ],
        ];
    }

    /**
     * Compute all 5 monthly metrics for the given calendar month.
     *
     * Args:
     *     year: 4-digit year.
     *     month: 1-12.
     *
     * Returns:
     *     Map of concept code => quantity (int). Sums/counts are non-negative.
     *
     * Raises:
     *     \Throwable: If the basemaquila connection or a query fails (the caller
     *         decides whether to log-and-skip or abort).
     */
    public function metricsForMonth(int $year, int $month): array
    {
        return [
            self::CODE_MAQUILA_MANDADA => $this->maquilaMandada($year, $month),
            self::CODE_MAQUILA_RECIBIDA => $this->maquilaRecibida($year, $month),
            self::CODE_ORDENES_CORTE => $this->ordenesCorte($year, $month),
            self::CODE_ORDENES_FUSION => $this->ordenesFusion($year, $month),
            self::CODE_ORDENES_BANDERAS => $this->ordenesBanderas($year, $month),
            self::CODE_ORDENES_CORTADAS => $this->ordenesCortadas($year, $month),
        ];
    }

    /**
     * Compute a single metric by concept code.
     */
    public function metricForCode(string $code, int $year, int $month): int
    {
        return match ($code) {
            self::CODE_MAQUILA_MANDADA => $this->maquilaMandada($year, $month),
            self::CODE_MAQUILA_RECIBIDA => $this->maquilaRecibida($year, $month),
            self::CODE_ORDENES_CORTE => $this->ordenesCorte($year, $month),
            self::CODE_ORDENES_FUSION => $this->ordenesFusion($year, $month),
            self::CODE_ORDENES_BANDERAS => $this->ordenesBanderas($year, $month),
            self::CODE_ORDENES_CORTADAS => $this->ordenesCortadas($year, $month),
            default => 0,
        };
    }

    private function maquilaMandada(int $year, int $month): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(cantidad_mandada), 0) AS n
             FROM maquila_entregada
             WHERE proceso = ? AND tipo <> ? AND YEAR(fecha) = ? AND MONTH(fecha) = ?',
            ['MAQUILA', 'CANCELADA', $year, $month],
        );
    }

    private function maquilaRecibida(int $year, int $month): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(cantidad_recibida), 0) AS n
             FROM maquila_recibida
             WHERE proceso = ? AND tipo <> ? AND YEAR(fecha_alta) = ? AND MONTH(fecha_alta) = ?',
            ['MAQUILA', 'CANCELADA', $year, $month],
        );
    }

    private function ordenesCorte(int $year, int $month): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) AS n
             FROM corte_alta
             WHERE tipo <> ? AND YEAR(fecha_alta) = ? AND MONTH(fecha_alta) = ?',
            ['CANCELADO', $year, $month],
        );
    }

    private function ordenesFusion(int $year, int $month): int
    {
        // Sólo cuentan las órdenes de fusión que tienen `cortador2` con nombre
        // ("esos son los que se pagan"); el conteo lo cobran los empleados que
        // el admin asigne al concepto.
        return (int) $this->scalar(
            "SELECT COUNT(*) AS n
             FROM combinacion_alta
             WHERE noorden LIKE 'F%' AND tipo <> ?
               AND cortador2 IS NOT NULL AND LTRIM(RTRIM(cortador2)) <> ''
               AND YEAR(fecha_alta) = ? AND MONTH(fecha_alta) = ?",
            ['CANCELADO', $year, $month],
        );
    }

    /**
     * Banderas is, per the operator's decision, the literal COUNT of corte_alta
     * (same as Órdenes de corte) — a separate payable concept with its own cost.
     */
    private function ordenesBanderas(int $year, int $month): int
    {
        return $this->ordenesCorte($year, $month);
    }

    /**
     * Órdenes cortadas: como Órdenes de corte pero sólo las que tienen `cortador2`
     * con nombre ("esos son los que se pagan"). El conteo lo cobran los empleados
     * que el admin asigne al concepto.
     */
    private function ordenesCortadas(int $year, int $month): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) AS n
             FROM corte_alta
             WHERE tipo <> ?
               AND cortador2 IS NOT NULL AND LTRIM(RTRIM(cortador2)) <> ''
               AND YEAR(fecha_alta) = ? AND MONTH(fecha_alta) = ?",
            ['CANCELADO', $year, $month],
        );
    }

    /**
     * Run a scalar query on the basemaquila connection and return column `n`.
     */
    private function scalar(string $sql, array $bindings): int|float
    {
        $row = DB::connection('basemaquila')->selectOne($sql, $bindings);

        return $row?->n ?? 0;
    }
}
