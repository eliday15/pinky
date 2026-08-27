<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Baja capturada DESPUÉS de calcular el periodo (Luis 2026-08-27, caso Dulce
 * Rocio): su entry quedaba huérfana pagando extras — la nómina no la volvía a
 * tocar porque el recálculo solo itera activos. La regla: la baja sale de la
 * nómina (todo va por finiquito); el recálculo completo elimina las entries
 * de quien ya no está en el universo del periodo.
 */
class TerminatedEntryCleanupTest extends FeatureTestCase
{
    public function test_full_recalculation_removes_entries_of_terminated_employees(): void
    {
        $active = Employee::factory()->create(['status' => 'active', 'daily_salary' => 300]);
        $baja = Employee::factory()->create(['status' => 'active', 'daily_salary' => 300]);

        $period = PayrollPeriod::factory()->create([
            'type' => 'weekly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'payment_date' => '2026-06-10',
            'status' => 'review',
        ]);

        $calc = app(PayrollCalculatorService::class);
        $calc->calculatePeriod($period);
        $this->assertNotNull(PayrollEntry::where('payroll_period_id', $period->id)->where('employee_id', $baja->id)->first());

        // Se captura la baja DESPUÉS del cálculo.
        $baja->update(['status' => 'terminated', 'termination_date' => '2026-05-30']);

        $calc->calculatePeriod($period->fresh());

        $this->assertNull(
            PayrollEntry::where('payroll_period_id', $period->id)->where('employee_id', $baja->id)->first(),
            'la entry de la baja se elimina en el recálculo completo'
        );
        $this->assertNotNull(
            PayrollEntry::where('payroll_period_id', $period->id)->where('employee_id', $active->id)->first(),
            'los activos conservan su entry'
        );
    }
}
