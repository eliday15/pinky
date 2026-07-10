<?php

namespace App\Services\Fiscal;

use App\Models\Employee;
use App\Models\SystemSetting;
use App\Models\VacationTable;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Salario Diario Integrado (SDI) y Salario Base de Cotización (SBC).
 *
 * Factor de integración (Art. 27 LSS, prestaciones mínimas de ley):
 *   factor = (365 + días_aguinaldo + días_vacaciones × prima%) / 365
 *   SDI = salario_diario × factor
 *   SBC = min(SDI, tope_UMA × UMA)
 *
 * Los días de vacaciones son los del año de servicio que el empleado CUMPLE EN
 * EL AÑO CALENDARIO (año actual − año de ingreso, mínimo 1; criterio Contpaq
 * verificado), tomados de la tabla LFT (`vacation_tables`). VERIFICADO contra
 * los 158 SDI de Contpaq Sem28 en prod: convención calendario = 156/158
 * exactos (los 2 restantes son datos de antigüedad: FOFH-010 reingreso,
 * RIMG-710 alta IMSS 2016 vs hire 2015). Con "año cumplido por aniversario"
 * salían 33 un escalón abajo (los 32 con aniversario aún no llegado) y con
 * "año en curso" ~45 un escalón arriba. Fórmula de los 11 factores empíricos:
 * 1.0493 año 1 … 1.0616 año 26-30, con aguinaldo 15 y prima 25%.
 */
class SdiCalculatorService
{
    private float $uma;

    private float $sbcCapUma;

    private float $aguinaldoDays;

    private ?Collection $vacationTable = null;

    public function __construct()
    {
        $this->uma = (float) SystemSetting::get('fiscal_uma_daily', 117.31);
        $this->sbcCapUma = (float) SystemSetting::get('fiscal_sbc_cap_uma', 25);
        $this->aguinaldoDays = (float) SystemSetting::get('fiscal_aguinaldo_days', 15);
    }

    /**
     * Factor de integración del empleado a una fecha dada.
     *
     * Args:
     *     employee: Empleado (usa hire_date y vacation_premium_percentage).
     *     asOf: Fecha de referencia para la antigüedad (default hoy).
     *
     * Returns:
     *     Factor (p.ej. 1.0493 para el primer año con prestaciones mínimas).
     */
    public function integrationFactor(Employee $employee, ?Carbon $asOf = null): float
    {
        $vacationDays = $this->vacationDaysForFactor($employee, $asOf);
        $prima = (float) ($employee->vacation_premium_percentage ?? 25) / 100;

        return (365 + $this->aguinaldoDays + $vacationDays * $prima) / 365;
    }

    /**
     * SDI = salario diario × factor de integración, redondeado a centavos.
     */
    public function sdi(Employee $employee, ?Carbon $asOf = null): float
    {
        $dailySalary = (float) $employee->daily_salary_computed;
        if ($dailySalary <= 0) {
            return 0.0;
        }

        return round($dailySalary * $this->integrationFactor($employee, $asOf), 2);
    }

    /**
     * SBC = SDI topado a N UMA (default 25).
     */
    public function sbc(Employee $employee, ?Carbon $asOf = null): float
    {
        $sdi = $this->sdi($employee, $asOf);

        return round(min($sdi, $this->sbcCapUma * $this->uma), 2);
    }

    /**
     * Días de vacaciones para el factor: los del año de servicio que CUMPLE EN
     * EL AÑO CALENDARIO de la fecha de referencia (año − año de ingreso,
     * mínimo 1; criterio Contpaq, validado 156/158 en prod), según la tabla
     * LFT. Sin hire_date se asume el primer año.
     */
    public function vacationDaysForFactor(Employee $employee, ?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? Carbon::now();
        $factorYear = $employee->hire_date
            ? max(1, (int) $asOf->year - (int) $employee->hire_date->year)
            : 1;

        $table = $this->vacationTable ??= VacationTable::orderBy('years_of_service')->get();
        $entry = $table->where('years_of_service', '<=', $factorYear)->last();

        // Fallback LFT año 1 si la tabla está vacía (no debería en prod).
        return (int) ($entry?->vacation_days ?? 12);
    }
}
