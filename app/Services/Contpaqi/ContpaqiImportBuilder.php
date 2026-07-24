<?php

namespace App\Services\Contpaqi;

use App\Models\Incident;
use App\Models\PayrollPeriod;
use Carbon\Carbon;

/**
 * Arma la hoja "Movimientos" de la plantilla de importación a CONTPAQi Nóminas
 * (la que mandó Luis, Vestidos Pinky 2026-07-24): UNA fila por empleado con solo
 * los MOVIMIENTOS VARIABLES de la semana. NO incluye sueldo, ISR, IMSS, séptimo
 * día ni prima vacacional — CONTPAQi los calcula solo.
 *
 * Los importes/días salen de lo que ya calculó la nómina (PayrollEntry +
 * calculation_breakdown['compensation_concepts']); cada concepto de Pinky se
 * mapea a su columna por código o por nombre (patrones ajustables abajo). El
 * mapeo de los conceptos "extra" (puntualidad, comisiones, etc.) depende del
 * catálogo real de prod, así que se valida con un archivo real y se ajusta aquí.
 */
class ContpaqiImportBuilder
{
    /** Encabezados EXACTOS de la hoja Movimientos de la plantilla de Luis. */
    public const HEADERS = [
        'CODIGO EMPLEADO',
        'NOMBRE (REFERENCIA)',
        'SEMANA',
        'FECHA INICIAL',
        'FECHA FINAL',
        'AUSENCIAS (DIAS)',
        'VACACIONES (DIAS)',
        'INCAPACIDAD (DIAS)',
        'H.E. DOBLES (HORAS)',
        'H.E. TRIPLES (HORAS)',
        'BONO PUNTUALIDAD ($)',
        'INCENTIVO PRODUCTIVIDAD ($)',
        'COMISIONES ($)',
        'GRATIFICACION ($)',
        'COMPENSACION ($)',
        'PREMIO EFICIENCIA ($)',
        'DIA FESTIVO / DESCANSO (DIAS)',
        'DESCANSO LABORADO (DIAS)',
        'FONACOT ($)',
        'PRESTAMO EMPRESA ($)',
        'ANTICIPO SUELDO ($)',
        'DEDUCCION GENERAL ($)',
        'OBSERVACIONES',
        'ESTATUS VALIDACION',
    ];

    /**
     * Reglas de mapeo concepto→columna de importe/horas/días. Para cada columna:
     * 'unit' = qué se suma (amount|hours|days), 'codes' = códigos exactos de
     * CompensationType, 'names' = subcadenas en el nombre (case-insensitive),
     * 'sign' = 'positive' (percepciones) | 'negative' (deducciones, se usa abs).
     * AJUSTABLE: al validar contra un archivo real de prod se afinan los patrones.
     *
     * @var array<int, array<string, mixed>>  índice de columna (0-based) => regla
     */
    private const CONCEPT_RULES = [
        8 => ['unit' => 'hours', 'codes' => ['HED'], 'names' => ['doble'], 'sign' => 'positive'],
        9 => ['unit' => 'hours', 'codes' => ['HET'], 'names' => ['triple'], 'sign' => 'positive'],
        10 => ['unit' => 'amount', 'codes' => [], 'names' => ['puntualidad'], 'sign' => 'positive'],
        11 => ['unit' => 'amount', 'codes' => [], 'names' => ['incentivo', 'productividad'], 'sign' => 'positive'],
        12 => ['unit' => 'amount', 'codes' => [], 'names' => ['comision'], 'sign' => 'positive'],
        13 => ['unit' => 'amount', 'codes' => [], 'names' => ['gratific'], 'sign' => 'positive'],
        14 => ['unit' => 'amount', 'codes' => [], 'names' => ['compensaci'], 'sign' => 'positive'],
        15 => ['unit' => 'amount', 'codes' => [], 'names' => ['eficiencia', 'premio'], 'sign' => 'positive'],
        16 => ['unit' => 'days', 'codes' => ['FEST'], 'names' => ['festivo'], 'sign' => 'positive'],
        17 => ['unit' => 'days', 'codes' => ['FIN'], 'names' => ['descanso laborado', 'fin de semana'], 'sign' => 'positive'],
        18 => ['unit' => 'amount', 'codes' => [], 'names' => ['fonacot'], 'sign' => 'negative'],
        19 => ['unit' => 'amount', 'codes' => [], 'names' => ['prestamo', 'préstamo'], 'sign' => 'negative'],
        20 => ['unit' => 'amount', 'codes' => [], 'names' => ['anticipo'], 'sign' => 'negative'],
        21 => ['unit' => 'amount', 'codes' => [], 'names' => ['deduccion general', 'deducción general'], 'sign' => 'negative'],
    ];

    /**
     * Filas de datos (una por empleado) para la hoja Movimientos.
     *
     * @return list<array<int, mixed>>
     */
    public function rows(PayrollPeriod $period): array
    {
        $entries = $period->entries()->with('employee')->get();

        $semana = $period->start_date instanceof Carbon
            ? $period->start_date->isoWeek()
            : Carbon::parse($period->start_date)->isoWeek();
        $fechaInicial = Carbon::parse($period->start_date)->format('d/m/Y');
        $fechaFinal = Carbon::parse($period->end_date)->format('d/m/Y');

        $incapacidadDays = $this->incapacidadDaysByEmployee(
            $entries->pluck('employee_id')->all(),
            Carbon::parse($period->start_date),
            Carbon::parse($period->end_date),
        );

        $rows = [];
        foreach ($entries as $entry) {
            $employee = $entry->employee;
            if (! $employee) {
                continue;
            }

            $breakdown = is_array($entry->calculation_breakdown) ? $entry->calculation_breakdown : [];
            $concepts = $breakdown['compensation_concepts'] ?? [];

            $row = array_fill(0, count(self::HEADERS), 0);
            $row[0] = $employee->contpaqi_identifier;
            $row[1] = $employee->full_name;
            $row[2] = $semana;
            $row[3] = $fechaInicial;
            $row[4] = $fechaFinal;
            $row[5] = (int) ($breakdown['base']['absence_deduction_days'] ?? $entry->days_absent);
            $row[6] = (int) $entry->vacation_days_paid;
            $row[7] = $incapacidadDays[$employee->id] ?? 0;

            foreach (self::CONCEPT_RULES as $col => $rule) {
                $row[$col] = $this->sumConcepts($concepts, $rule);
            }

            $row[22] = ''; // OBSERVACIONES (captura manual)
            $row[23] = ''; // ESTATUS VALIDACION (lo llena la validación)

            $rows[] = $row;
        }

        // Orden estable por nombre para que el archivo sea comparable semana a semana.
        usort($rows, fn ($a, $b) => strcmp((string) $a[1], (string) $b[1]));

        return $rows;
    }

    /**
     * Suma el importe/horas/días de los conceptos que casan con la regla.
     *
     * @param  array<int, array<string, mixed>>  $concepts
     * @param  array<string, mixed>  $rule
     */
    private function sumConcepts(array $concepts, array $rule): float|int
    {
        $total = 0.0;
        foreach ($concepts as $concept) {
            $amount = (float) ($concept['amount'] ?? 0);
            $isDeduction = $amount < 0;
            $wantDeduction = ($rule['sign'] ?? 'positive') === 'negative';
            if ($wantDeduction !== $isDeduction) {
                continue;
            }
            if (! $this->conceptMatches($concept, $rule)) {
                continue;
            }

            $total += match ($rule['unit']) {
                'hours' => (float) ($concept['hours'] ?? 0),
                'days' => (float) ($concept['days'] ?? 0),
                default => abs($amount),
            };
        }

        // Enteros limpios para columnas de días/horas cuando no hay fracción.
        return $total == (int) $total ? (int) $total : round($total, 2);
    }

    /**
     * @param  array<string, mixed>  $concept
     * @param  array<string, mixed>  $rule
     */
    private function conceptMatches(array $concept, array $rule): bool
    {
        $code = strtoupper((string) ($concept['code'] ?? ''));
        if (in_array($code, $rule['codes'], true)) {
            return true;
        }

        $name = mb_strtolower((string) ($concept['name'] ?? ''));
        foreach ($rule['names'] as $needle) {
            if ($needle !== '' && str_contains($name, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Días de incapacidad (calendario, estándar IMSS) por empleado que solapan
     * la semana, desde las incidencias aprobadas de categoría sick_leave.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, int>  employee_id => días
     */
    private function incapacidadDaysByEmployee(array $employeeIds, Carbon $start, Carbon $end): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $incidents = Incident::query()
            ->approved()
            ->with('incidentType')
            ->whereIn('employee_id', $employeeIds)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->whereHas('incidentType', fn ($q) => $q->where('category', 'sick_leave'))
            ->get();

        $days = [];
        foreach ($incidents as $incident) {
            $from = Carbon::parse($incident->start_date)->max($start);
            $to = Carbon::parse($incident->end_date)->min($end);
            if ($from->gt($to)) {
                continue;
            }
            $days[$incident->employee_id] = ($days[$incident->employee_id] ?? 0)
                + ((int) $from->diffInDays($to) + 1);
        }

        return $days;
    }
}
