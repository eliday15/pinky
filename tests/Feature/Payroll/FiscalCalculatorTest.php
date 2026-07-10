<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\Fiscal\ImssCalculatorService;
use App\Services\Fiscal\InfonavitCalculatorService;
use App\Services\Fiscal\IsrCalculatorService;
use App\Services\PayrollCalculatorService;
use Database\Seeders\FiscalSettingsSeeder;
use Tests\FeatureTestCase;

/**
 * Motor fiscal (Nivel 1): ISR + IMSS + Infonavit validados contra los montos
 * EXACTOS del PDF de Contpaq Semana 28. La tarifa/tasas se siembran con
 * FiscalSettingsSeeder (valores 2026 calibrados).
 */
class FiscalCalculatorTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiscalSettingsSeeder::class);
    }

    // ---- IMSS (Contpaq: SBC 420.26 × 7 días → $71.78) ----
    public function test_imss_worker_quota_matches_contpaq(): void
    {
        $imss = app(ImssCalculatorService::class);
        // Empleado normal (sal_diario > mínimo): 2.375% + 0.40% excedente de 3 UMA.
        $this->assertEqualsWithDelta(71.78, $imss->workerQuota(420.26, 7, 396.88), 0.60);
    }

    public function test_imss_zero_for_minimum_wage(): void
    {
        $imss = app(ImssCalculatorService::class);
        // Salario mínimo: cuota obrera 0 (Art. 36 LSS).
        $this->assertSame(0.0, $imss->workerQuota(330.58, 7, 315.04));
    }

    // ---- ISR (tarifa semanal calibrada; Contpaq gravable 2909.20 → 234.29) ----
    public function test_isr_matches_contpaq_16pct_bracket(): void
    {
        $isr = app(IsrCalculatorService::class);
        $r = $isr->calculate(2909.20, 415.60); // sal_diario > mínimo, sin subsidio
        $this->assertEqualsWithDelta(234.29, $r['isr'], 0.50);
    }

    public function test_isr_zero_for_minimum_wage(): void
    {
        $isr = app(IsrCalculatorService::class);
        // Salario mínimo: exento (Contpaq isr_mes = 0 para los base-$2205).
        $r = $isr->calculate(2205.28, 315.04);
        $this->assertSame(0.0, $r['isr']);
    }

    public function test_isr_applies_subsidy(): void
    {
        $isr = app(IsrCalculatorService::class);
        // Contpaq: gravable 2327.50 → isr_antes 170.57 − subsidio 123.34 = 47.23.
        $r = $isr->calculate(2327.50, 332.50);
        $this->assertEqualsWithDelta(47.23, $r['isr'], 0.50);
    }

    // ---- Infonavit ----
    public function test_infonavit_cf_fixed_weekly(): void
    {
        $inf = app(InfonavitCalculatorService::class);
        $emp = Employee::factory()->make([
            'infonavit_credit_type' => 'cf',
            'infonavit_credit_value' => 403.85,
        ]);
        // Semana completa → la cuota fija semanal completa.
        $this->assertEqualsWithDelta(403.85, $inf->deduction($emp, 334.46, 7.0, 7.0), 0.01);
        // Media semana → prorrateada.
        $this->assertEqualsWithDelta(201.93, $inf->deduction($emp, 334.46, 3.5, 7.0), 0.02);
    }

    public function test_infonavit_none_returns_zero(): void
    {
        $inf = app(InfonavitCalculatorService::class);
        $emp = Employee::factory()->make(['infonavit_credit_type' => 'none', 'infonavit_credit_value' => null]);
        $this->assertSame(0.0, $inf->deduction($emp, 330.58, 7.0));
    }

    // ---- Enganche en la nómina: con el flag prendido, la retención baja el banco ----
    public function test_retentions_reduce_bank_transfer_when_enabled(): void
    {
        SystemSetting::updateOrCreate(['key' => 'fiscal_retentions_enabled'], ['value' => '1']);

        $emp = Employee::factory()->create([
            'status' => 'active', 'daily_salary' => 500.00, 'hire_date' => '2025-01-01',
            'is_imss_enrolled' => true, 'imss_number' => '12-34-56-7890-1', 'is_trial_period' => false,
            'sdi' => 525.00, 'sbc' => 525.00,
        ]);
        AttendanceRecord::factory()->for($emp)->create([
            'work_date' => '2026-06-03', 'status' => 'present', 'worked_hours' => 8.00,
        ]);
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01', 'end_date' => '2026-06-07',
        ]);

        $entry = app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $emp);

        // base = 500 × 7 = 3500. Con retenciones: ISR e IMSS > 0, y el banco baja.
        $this->assertGreaterThan(0, (float) $entry->isr_amount, 'ISR retenido');
        $this->assertGreaterThan(0, (float) $entry->imss_amount, 'IMSS retenido');
        $this->assertEqualsWithDelta(
            3500 - (float) $entry->isr_amount - (float) $entry->imss_amount,
            (float) $entry->bank_amount, 0.50,
            'la transferencia = base − ISR − IMSS'
        );
    }

    public function test_retentions_skipped_for_cash_employees(): void
    {
        SystemSetting::updateOrCreate(['key' => 'fiscal_retentions_enabled'], ['value' => '1']);

        // Empleado en efectivo (sin IMSS): NO se le retiene.
        $emp = Employee::factory()->create([
            'status' => 'active', 'daily_salary' => 500.00, 'hire_date' => '2025-01-01',
            'is_imss_enrolled' => false, 'is_trial_period' => true,
        ]);
        AttendanceRecord::factory()->for($emp)->create([
            'work_date' => '2026-06-03', 'status' => 'present', 'worked_hours' => 8.00,
        ]);
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01', 'end_date' => '2026-06-07',
        ]);

        $entry = app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $emp);

        $this->assertSame('0.00', (string) $entry->isr_amount);
        $this->assertSame('0.00', (string) $entry->imss_amount);
    }
}
