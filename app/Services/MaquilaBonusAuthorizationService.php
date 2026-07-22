<?php

namespace App\Services;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera (o actualiza) las autorizaciones mensuales de los bonos de maquila.
 *
 * Para el mes dado: consulta las cantidades en basemaquila
 * ([[MaquilaBonusMetricsService]]) y, por cada concepto, crea una autorización
 * PENDIENTE por cada empleado asignado al concepto (pivote
 * employee_compensation_type activo). La cantidad va en `hours` (usada como
 * unidades para one_time) y el monto se calcula en la nómina como
 * fixed_amount × unidades. Nadie las captura a mano: se llenan solas.
 *
 * Idempotente: se identifica cada autorización por (concepto, empleado, mes) vía
 * `bulk_group_id`. Si ya existe y sigue PENDIENTE, se actualiza la cantidad; si
 * ya fue APROBADA/PAGADA/RECHAZADA, se respeta y no se toca.
 */
class MaquilaBonusAuthorizationService
{
    private const MONTHS_ES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function __construct(private readonly MaquilaBonusMetricsService $metrics)
    {
    }

    /**
     * Genera/actualiza las autorizaciones de los 5 conceptos para un mes.
     *
     * Args:
     *     year: año de 4 dígitos.
     *     month: 1-12.
     *     requestedBy: id del usuario que dispara (superadmin); si es null se
     *         resuelve el primer superadmin como solicitante del sistema.
     *     dryRun: si true, sólo calcula y NO escribe.
     *
     * Returns:
     *     Resumen por concepto: code, name, quantity, assigned, created, updated,
     *     locked (aprobadas/pagadas/rechazadas que no se tocaron).
     *
     * @return array<int, array<string, mixed>>
     */
    public function generateForMonth(int $year, int $month, ?int $requestedBy = null, bool $dryRun = false): array
    {
        $requestedBy ??= $this->resolveSystemRequester();

        if ($requestedBy === null) {
            throw new \RuntimeException('No hay ningún usuario superadmin para registrar como solicitante de las autorizaciones.');
        }

        $quantities = $this->metrics->metricsForMonth($year, $month);
        $date = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $groupId = sprintf('MAQBONO-%04d-%02d', $year, $month);
        $monthLabel = self::MONTHS_ES[$month] . ' ' . $year;

        $summary = [];

        $concepts = CompensationType::whereIn('code', array_keys($quantities))
            ->with(['employees' => fn ($q) => $q->where('employee_compensation_type.is_active', true)])
            ->get()
            ->keyBy('code');

        foreach ($quantities as $code => $quantity) {
            $concept = $concepts->get($code);

            $row = [
                'code' => $code,
                'name' => $concept?->name ?? $code,
                'quantity' => $quantity,
                'assigned' => 0,
                'created' => 0,
                'updated' => 0,
                'locked' => 0,
            ];

            if ($concept === null) {
                $row['note'] = 'concepto no existe (corre el seeder)';
                $summary[] = $row;

                continue;
            }

            $employees = $concept->employees;
            $row['assigned'] = $employees->count();

            // Nada que pagar (0 unidades) o sin empleados asignados: no se generan.
            if ($quantity <= 0 || $employees->isEmpty()) {
                $summary[] = $row;

                continue;
            }

            foreach ($employees as $employee) {
                $existing = Authorization::where('compensation_type_id', $concept->id)
                    ->where('employee_id', $employee->id)
                    ->where('bulk_group_id', $groupId)
                    ->first();

                if ($existing !== null && $existing->status !== Authorization::STATUS_PENDING) {
                    $row['locked']++;

                    continue;
                }

                if ($dryRun) {
                    $existing !== null ? $row['updated']++ : $row['created']++;

                    continue;
                }

                $reason = sprintf(
                    'Bono de maquila — %s — %s — %s unidades (generado automáticamente desde basemaquila).',
                    $concept->name,
                    $monthLabel,
                    number_format($quantity),
                );

                if ($existing !== null) {
                    $existing->update(['hours' => $quantity, 'reason' => $reason]);
                    $row['updated']++;

                    continue;
                }

                Authorization::create([
                    'employee_id' => $employee->id,
                    'requested_by' => $requestedBy,
                    'type' => Authorization::TYPE_SPECIAL,
                    'compensation_type_id' => $concept->id,
                    'date' => $date,
                    'hours' => $quantity,
                    'reason' => $reason,
                    'status' => Authorization::STATUS_PENDING,
                    'is_pre_authorization' => false,
                    'is_bulk_generated' => true,
                    'bulk_group_id' => $groupId,
                ]);
                $row['created']++;
            }

            $summary[] = $row;
        }

        return $summary;
    }

    /**
     * Resolve the first superadmin user id, used as the system requester when a
     * scheduled run has no acting user.
     */
    private function resolveSystemRequester(): ?int
    {
        return User::role('superadmin')->orderBy('id')->value('id')
            ?? User::orderBy('id')->value('id');
    }
}
