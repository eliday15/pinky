<?php

namespace App\Services;

use App\Models\SystemSetting;
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

    private function ordenesFusion(int $year, int $month, ?string $cortador = null): int
    {
        // Sólo cuentan las órdenes de fusión que pasan el filtro de cortador2
        // ("esos son los que se pagan"); el conteo lo cobran los empleados que
        // el admin asigne al concepto.
        $sql = "SELECT COUNT(*) AS n FROM combinacion_alta WHERE noorden LIKE 'F%' AND tipo <> ?";
        $bindings = ['CANCELADO'];
        $this->appendCortador2Filter(self::CODE_ORDENES_FUSION, $sql, $bindings, $cortador);
        $sql .= ' AND YEAR(fecha_alta) = ? AND MONTH(fecha_alta) = ?';
        $bindings[] = $year;
        $bindings[] = $month;

        return (int) $this->scalar($sql, $bindings);
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
    private function ordenesCortadas(int $year, int $month, ?string $cortador = null): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM corte_alta WHERE tipo <> ?';
        $bindings = ['CANCELADO'];
        $this->appendCortador2Filter(self::CODE_ORDENES_CORTADAS, $sql, $bindings, $cortador);
        $sql .= ' AND YEAR(fecha_alta) = ? AND MONTH(fecha_alta) = ?';
        $bindings[] = $year;
        $bindings[] = $month;

        return (int) $this->scalar($sql, $bindings);
    }

    /**
     * Cuántas órdenes del mes cortó una persona en concreto (Luis 2026-10-08:
     * "cada quien cobra lo que cortó según la lista de cortadores").
     *
     * Devuelve 0 con el nombre vacío: sin cortador asignado no hay nada que
     * cobrar — y la pantalla lo señala en vez de pagarle el conteo de todos.
     */
    public function quantityForCortador(string $code, int $year, int $month, string $cortador): int
    {
        $name = trim($cortador);

        if ($name === '' || ! in_array($code, self::cortador2FilteredCodes(), true)) {
            return 0;
        }

        return match ($code) {
            self::CODE_ORDENES_FUSION => $this->ordenesFusion($year, $month, $name),
            self::CODE_ORDENES_CORTADAS => $this->ordenesCortadas($year, $month, $name),
            default => 0,
        };
    }

    /**
     * Nombres que REALMENTE existen en la columna `cortador2` del último año.
     *
     * Alimenta la lista del módulo: antes el nombre se escribía a mano y una
     * letra o un espacio de más dejaba el conteo en cero sin avisar.
     *
     * @return list<string>
     */
    public function availableCortadores(string $code): array
    {
        $table = match ($code) {
            self::CODE_ORDENES_FUSION => 'combinacion_alta',
            self::CODE_ORDENES_CORTADAS => 'corte_alta',
            default => null,
        };

        if ($table === null) {
            return [];
        }

        $sql = "SELECT DISTINCT LTRIM(RTRIM(cortador2)) AS nombre
                FROM {$table}
                WHERE cortador2 IS NOT NULL AND LTRIM(RTRIM(cortador2)) <> ''
                  AND fecha_alta >= ?
                ORDER BY nombre";

        $rows = DB::connection('basemaquila')->select($sql, [
            now()->subYear()->startOfDay()->toDateTimeString(),
        ]);

        return collect($rows)
            ->pluck('nombre')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Llave del mapa empleado → cortador de un concepto. */
    public static function cortadorMapSettingKey(string $code): string
    {
        return 'maquila_bonus_cortador_map:'.$code;
    }

    /**
     * Mapa empleado → nombre del cortador que cobra, para un concepto.
     *
     * @return array<int, string>
     */
    public function cortadorMapFor(string $code): array
    {
        $raw = (string) SystemSetting::get(self::cortadorMapSettingKey($code), '');

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $employeeId => $name) {
            $name = trim((string) $name);
            if ((int) $employeeId > 0 && $name !== '') {
                $map[(int) $employeeId] = $name;
            }
        }

        return $map;
    }

    /** El cortador que cobra un empleado en un concepto (vacío = sin asignar). */
    public function cortadorForEmployee(string $code, int $employeeId): string
    {
        return $this->cortadorMapFor($code)[$employeeId] ?? '';
    }

    /**
     * Asigna (o quita, con nombre vacío) el cortador que cobra un empleado.
     */
    public function setCortadorForEmployee(string $code, int $employeeId, string $cortador): void
    {
        $map = $this->cortadorMapFor($code);
        $name = trim($cortador);

        if ($name === '') {
            unset($map[$employeeId]);
        } else {
            $map[$employeeId] = $name;
        }

        ksort($map);

        $key = self::cortadorMapSettingKey($code);

        SystemSetting::firstOrCreate(
            ['key' => $key],
            [
                'value' => '',
                'type' => 'string',
                'group' => SystemSetting::GROUP_PAYROLL,
                'label' => "Cortador que cobra cada empleado en {$code}",
                'description' => 'Mapa JSON empleado → nombre en cortador2: cada quien cobra solo las órdenes que cortó.',
            ],
        );

        SystemSetting::set($key, $map === [] ? '' : json_encode($map));
    }

    /**
     * Conceptos cuyo conteo se filtra por la columna `cortador2`.
     *
     * @return string[]
     */
    public static function cortador2FilteredCodes(): array
    {
        return [self::CODE_ORDENES_FUSION, self::CODE_ORDENES_CORTADAS];
    }

    /** Llave de SystemSetting con el nombre exacto de cortador2 para un concepto. */
    public static function cortador2SettingKey(string $code): string
    {
        return 'maquila_bonus_cortador2:' . $code;
    }

    /**
     * Nombre configurado en cortador2 para un concepto (vacío = cualquier
     * cortador2 con nombre).
     */
    public function cortador2NameFor(string $code): string
    {
        return trim((string) SystemSetting::get(self::cortador2SettingKey($code), ''));
    }

    /**
     * Guarda (creando la llave si no existe) el nombre de cortador2 de un
     * concepto. Vacío = cualquier cortador2 con nombre.
     */
    public function setCortador2NameFor(string $code, string $name): void
    {
        $key = self::cortador2SettingKey($code);

        SystemSetting::firstOrCreate(
            ['key' => $key],
            [
                'value' => '',
                'type' => 'string',
                'group' => SystemSetting::GROUP_PAYROLL,
                'label' => "Nombre de cortador2 para {$code}",
                'description' => 'Filtro de nombre exacto en cortador2 para el bono; vacío = cualquier cortador2 con nombre.',
            ],
        );

        SystemSetting::set($key, trim($name));
    }

    /**
     * Agrega al SQL el filtro de cortador2 según la config del concepto: nombre
     * exacto si está configurado, o "cualquier cortador2 con nombre" si vacío.
     */
    private function appendCortador2Filter(string $code, string &$sql, array &$bindings, ?string $cortador = null): void
    {
        // Un cortador explícito (el que cobra ese empleado) manda sobre el
        // filtro global del concepto.
        $name = $cortador !== null ? trim($cortador) : $this->cortador2NameFor($code);

        if ($name !== '') {
            $sql .= ' AND LTRIM(RTRIM(cortador2)) = ?';
            $bindings[] = $name;

            return;
        }

        $sql .= " AND cortador2 IS NOT NULL AND LTRIM(RTRIM(cortador2)) <> ''";
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
