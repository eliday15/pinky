<?php

namespace App\Services\Payroll;

use App\Models\Department;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PRENÓMINA (Luis 2026-11-25): cuánto va a cobrar cada persona en la semanal y
 * en la mensual ANTES de generar la nómina, para corregir antes de pagar.
 *
 * Corre el MISMO cálculo de producción (PayrollCalculatorService) sobre una
 * nómina semanal y una mensual armadas con las fechas que tocarían, dentro de
 * una transacción que SIEMPRE se revierte: no queda ningún periodo, recibo,
 * FRT ni corte de desayunos guardado (mismo patrón que PayrollShadowRecalcService).
 */
class PrenominaService
{
    public function __construct(
        private PayrollCalculatorService $calculator,
        private PrenominaLineBuilder $lines,
    ) {}

    /**
     * Fechas que usaría la próxima nómina del alcance: la semana que sigue a
     * la última nómina base y, en la General, los extras del mes desde donde
     * terminó el último mes pagado hasta el fin de esa semana.
     *
     * @return array{week_start: string, week_end: string, extras_start: ?string, extras_end: ?string}
     */
    public function defaultRange(?int $departmentId): array
    {
        $lastBase = PayrollPeriod::where('department_id', $departmentId)
            ->where('type', '!=', 'monthly')
            ->orderByDesc('end_date')
            ->first();

        $weekStart = $lastBase
            ? Carbon::parse($lastBase->end_date)->addDay()
            : Carbon::today()->startOfWeek(Carbon::WEDNESDAY);
        $weekEnd = $weekStart->copy()->addDays(6);

        if ($departmentId !== null && Department::whereKey($departmentId)->value('has_separate_payroll')) {
            return [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'extras_start' => null,
                'extras_end' => null,
            ];
        }

        $lastMonthlyEnd = PayrollPeriod::where('department_id', $departmentId)->where('type', 'monthly')->max('end_date');
        $lastExtrasEnd = PayrollPeriod::where('department_id', $departmentId)->max('extras_end_date');
        $lastExtras = collect([$lastMonthlyEnd, $lastExtrasEnd])->filter()->map(fn ($d) => Carbon::parse($d))->max();

        $extrasStart = $lastExtras ? $lastExtras->copy()->addDay() : $weekStart->copy();
        if ($extrasStart->gt($weekEnd)) {
            $extrasStart = $weekStart->copy();
        }

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'extras_start' => $extrasStart->toDateString(),
            'extras_end' => $weekEnd->toDateString(),
        ];
    }

    /**
     * Calcula la prenómina sin guardar nada.
     *
     * Args:
     *     departmentId: null = nómina General; id = departamento con nómina propia.
     *     weekStart/weekEnd: semana cuyo sueldo se paga.
     *     extrasStart/extrasEnd: extras del mes (null = sin mensual).
     *     withAmounts: false para quien no ve dinero (no sale ningún importe).
     *
     * Returns:
     *     ['rows' => por empleado, 'totals' => sumas (null sin importes)].
     */
    public function build(?int $departmentId, string $weekStart, string $weekEnd, ?string $extrasStart, ?string $extrasEnd, bool $withAmounts): array
    {
        DB::beginTransaction();
        try {
            $weekly = $this->calculate($departmentId, 'weekly', $weekStart, $weekEnd);
            $monthly = ($extrasStart && $extrasEnd)
                ? $this->calculate($departmentId, 'monthly', $extrasStart, $extrasEnd)
                : collect();
        } finally {
            DB::rollBack();
        }

        $employeeIds = $weekly->keys()->merge($monthly->keys())->unique();

        $rows = $employeeIds->map(function (int $employeeId) use ($weekly, $monthly, $withAmounts) {
            /** @var PayrollEntry|null $w */
            $w = $weekly->get($employeeId);
            /** @var PayrollEntry|null $m */
            $m = $monthly->get($employeeId);
            $employee = ($w ?? $m)->employee;

            $row = [
                'employee_id' => $employeeId,
                'employee_number' => $employee?->employee_number,
                'name' => $employee?->full_name,
                'department_id' => $employee?->department_id,
                'department' => $employee?->department?->name,
                'weekly' => $w ? $this->lines->forEntry($w, 'weekly') : null,
                'monthly' => $m ? $this->lines->forEntry($m, 'monthly') : null,
            ];
            $row['total'] = round((float) ($row['weekly']['net'] ?? 0) + (float) ($row['monthly']['net'] ?? 0), 2);
            $row['alerts'] = array_merge($row['weekly']['alerts'] ?? [], $row['monthly']['alerts'] ?? []);

            return $withAmounts ? $row : $this->withoutAmounts($row);
        })
            ->sortBy(fn (array $r) => mb_strtolower((string) $r['name']))
            ->values();

        return [
            'rows' => $rows->all(),
            'totals' => $withAmounts ? [
                'weekly' => round($rows->sum(fn ($r) => (float) ($r['weekly']['net'] ?? 0)), 2),
                'monthly' => round($rows->sum(fn ($r) => (float) ($r['monthly']['net'] ?? 0)), 2),
                'total' => round($rows->sum('total'), 2),
                'cash' => round($rows->sum(fn ($r) => (float) ($r['weekly']['cash'] ?? 0) + (float) ($r['monthly']['cash'] ?? 0)), 2),
                'bank' => round($rows->sum(fn ($r) => (float) ($r['weekly']['bank'] ?? 0) + (float) ($r['monthly']['bank'] ?? 0)), 2),
            ] : null,
        ];
    }

    /**
     * Arma y calcula un periodo dentro de la transacción abierta y devuelve
     * sus recibos (ya en memoria) indexados por empleado.
     *
     * @return Collection<int, PayrollEntry>
     */
    private function calculate(?int $departmentId, string $type, string $start, string $end): Collection
    {
        $period = PayrollPeriod::create([
            'name' => 'Prenómina '.$start.' al '.$end,
            'type' => $type,
            'department_id' => $departmentId,
            'start_date' => $start,
            'end_date' => $end,
            'payment_date' => $end,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        $this->calculator->calculatePeriod($period);

        return $period->entries()
            ->with(['employee.department'])
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Quita todo importe: quedan los conceptos con su cantidad (días, horas,
     * unidades) para quien autoriza lo humano pero no ve dinero.
     */
    private function withoutAmounts(array $row): array
    {
        foreach (['weekly', 'monthly'] as $key) {
            if ($row[$key] === null) {
                continue;
            }
            $row[$key] = [
                'lines' => array_map(
                    fn (array $line) => ['label' => $line['label'], 'detail' => $line['detail']],
                    array_values(array_filter($row[$key]['lines'], fn (array $line) => ! ($line['money_only'] ?? false))),
                ),
                'alerts' => $row[$key]['alerts'],
            ];
        }
        unset($row['total']);

        return $row;
    }
}
