<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Luis 2026-10-29: el "Bono por carga de trabajo" (ALM) pasó de mensual a
 * semanal el 1/10 y regresó a mensual el 8/10. Las semanales cerradas ya lo
 * habían pagado, y la mensual —cuyo rango de extras arranca el 23/09— lo iba a
 * pagar otra vez (23 bonos + Bt, $11,858). Una autorización que ya salió en
 * una nómina cerrada no se vuelve a pagar, y el recibo dice dónde se pagó.
 */
class AlreadyPaidAuthorizationGuardTest extends FeatureTestCase
{
    private function bonusType(): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Bono por carga de trabajo',
            'code' => 'ALMX',
            'calculation_type' => 'fixed',
            'fixed_amount' => 1.00,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => 'special',
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_active' => true,
        ]);
    }

    private function bonus(Employee $employee, CompensationType $type, string $date, float $amount): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $type->id,
            'date' => $date,
            'hours' => $amount,
            'status' => Authorization::STATUS_APPROVED,
            'approved_at' => '2026-09-30 09:00:00',
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    private function conceptAmount(PayrollEntry $entry, string $code): float
    {
        return (float) collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
            ->where('code', $code)->sum('amount');
    }

    public function test_bonus_paid_in_a_closed_week_is_not_paid_again_by_the_month(): void
    {
        $calculator = app(PayrollCalculatorService::class);
        $employee = Employee::factory()->create(['status' => 'active', 'daily_salary' => 500, 'hire_date' => '2025-01-01']);
        $type = $this->bonusType();
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);
        $this->bonus($employee, $type, '2026-09-29', 500);

        // La semana lo paga mientras el concepto es semanal, y se cierra.
        $week = PayrollPeriod::factory()->weekly()->create([
            'name' => 'Semana 23 sep - 29 sep',
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-29',
            'status' => 'review',
        ]);
        $calculator->calculatePeriod($week);
        $weekEntry = PayrollEntry::where('payroll_period_id', $week->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(500.0, $this->conceptAmount($weekEntry, 'ALMX'), 0.01, 'la semana paga el bono');
        $week->update(['status' => 'paid', 'cash_closed_at' => '2026-10-01 13:00:00']);

        // El concepto regresa a mensual; un bono nuevo de octubre sí toca.
        $type->update(['payment_period' => CompensationType::PAYMENT_PERIOD_MONTHLY]);
        $this->bonus($employee, $type, '2026-10-05', 300);

        $month = PayrollPeriod::factory()->create([
            'name' => 'Mes 23 sep - 27 oct',
            'type' => 'monthly',
            'start_date' => '2026-09-23',
            'end_date' => '2026-10-27',
            'status' => 'review',
        ]);

        // Camino en lote y camino de un solo empleado: mismo resultado.
        $calculator->calculatePeriod($month);
        $batch = PayrollEntry::where('payroll_period_id', $month->id)->where('employee_id', $employee->id)->firstOrFail();
        $single = $calculator->calculateEmployeePayroll($month->fresh(), $employee->fresh());

        foreach (['lote' => $batch, 'individual' => $single] as $path => $entry) {
            $this->assertEqualsWithDelta(300.0, $this->conceptAmount($entry, 'ALMX'), 0.01, "{$path}: solo paga el bono de octubre");
            $skipped = $entry->calculation_breakdown['already_paid_authorizations'] ?? [];
            $this->assertCount(1, $skipped, "{$path}: el del 29/09 se lista como ya pagado");
            $this->assertSame('2026-09-29', $skipped[0]['date']);
            $this->assertSame('Semana 23 sep - 29 sep', $skipped[0]['paid_in']);
        }

        // Recalcular la propia semana cerrada no se excluye a sí misma.
        $week->update(['status' => 'review']);
        $type->update(['payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY]);
        $recalc = $calculator->calculateEmployeePayroll($week->fresh(), $employee->fresh());
        $this->assertEqualsWithDelta(500.0, $this->conceptAmount($recalc, 'ALMX'), 0.01);
    }
}
