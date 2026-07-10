<?php

namespace Tests\Feature\Payroll;

use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Percepciones por TRANSFERENCIA de empleados formalizados (como Contpaq): el
 * bono de cumpleaños (1 día de sueldo la semana del cumpleaños) y los conceptos
 * marcados pays_via_transfer (aguinaldo, gratificaciones). Caen en la
 * transferencia (banco) junto con el sueldo, no en el efectivo.
 */
class TransferPerceptionsTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    /** Empleado FORMALIZADO (cobra base por transferencia). */
    private function formalized(array $attrs = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'is_imss_enrolled' => true,
            'imss_number' => '12345678901',
            'is_trial_period' => false,
            'is_attendance_exempt' => true, // base completo, sin ruido de faltas
        ], $attrs));
    }

    private function weekly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01', // lunes
            'end_date' => '2026-06-07',   // domingo
        ]);
    }

    // ---- Cumpleaños ----

    public function test_formalized_birthday_bonus_paid_in_weekly_transfer(): void
    {
        // Cumpleaños el 3 jun, dentro de la semana 1–7 jun.
        $employee = $this->formalized(['birth_date' => '1990-06-03']);
        $this->assertFalse($employee->paysBaseInCash());

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $cumple = collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
            ->firstWhere('code', 'CUMPLE');
        $this->assertNotNull($cumple, 'se genera el bono de cumpleaños');
        $this->assertEqualsWithDelta(800.00, (float) $cumple['amount'], 0.01, '1 día de sueldo');
        $this->assertTrue($cumple['via_transfer'] ?? false, 'se marca via_transfer');
        // Cae en la transferencia (banco), nunca en efectivo.
        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01, 'el cumpleaños no cae en efectivo');
        $this->assertGreaterThanOrEqual(800.00, (float) $entry->bank_amount, 'el bono va al banco');
    }

    public function test_no_birthday_bonus_outside_birthday_week(): void
    {
        // Cumpleaños en diciembre: no cae en la semana de junio.
        $employee = $this->formalized(['birth_date' => '1990-12-25']);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $cumple = collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
            ->firstWhere('code', 'CUMPLE');
        $this->assertNull($cumple, 'sin cumpleaños en la semana no hay bono');
    }

    public function test_no_birthday_bonus_without_birth_date(): void
    {
        // Sin fecha de nacimiento capturada: inerte (birth_date NULL → $0).
        $employee = $this->formalized(['birth_date' => null]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $cumple = collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
            ->firstWhere('code', 'CUMPLE');
        $this->assertNull($cumple, 'sin fecha de nacimiento no hay bono');
    }

    public function test_cash_employee_gets_no_birthday_bonus(): void
    {
        // Empleado de EFECTIVO (no formalizado) con cumpleaños en la semana: el
        // bono es solo para formalizados (transferencia), como Contpaq.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'birth_date' => '1990-06-03',
            'is_attendance_exempt' => true,
            // is_imss_enrolled=false por default → efectivo
        ]);
        $this->assertTrue($employee->paysBaseInCash());

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $cumple = collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
            ->firstWhere('code', 'CUMPLE');
        $this->assertNull($cumple, 'los de efectivo no cobran cumpleaños en esta regla');
    }

    // ---- Concepto marcado pays_via_transfer ----

    public function test_transfer_flagged_concept_pays_via_bank_for_formalized(): void
    {
        $employee = $this->formalized();

        $type = CompensationType::factory()->fixed(500.00)->create([
            'code' => 'AGUIN',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_recurring' => true,
            'pays_via_transfer' => true,
        ]);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        // El concepto cuenta en gross/net vía other_compensation_pay...
        $this->assertEqualsWithDelta(500.00, (float) $entry->other_compensation_pay, 0.01);
        // ...pero cae en la TRANSFERENCIA (banco), no en el efectivo.
        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01, 'el concepto por transferencia no cae en efectivo');
        $concepts = collect($entry->calculation_breakdown['compensation_concepts'] ?? []);
        $this->assertTrue(
            $concepts->contains(fn ($c) => ($c['code'] ?? '') === 'AGUIN' && ($c['via_transfer'] ?? false) === true),
            'el concepto se marca via_transfer',
        );
    }

    public function test_transfer_flagged_concept_stays_cash_for_informal_employee(): void
    {
        // Empleado de EFECTIVO: aunque el concepto esté marcado pays_via_transfer,
        // no hay transferencia a la que mandarlo → se queda en efectivo.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'is_attendance_exempt' => true,
        ]);
        $this->assertTrue($employee->paysBaseInCash());

        $type = CompensationType::factory()->fixed(500.00)->create([
            'code' => 'AGUIN',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_recurring' => true,
            'pays_via_transfer' => true,
        ]);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $concepts = collect($entry->calculation_breakdown['compensation_concepts'] ?? []);
        $this->assertFalse(
            $concepts->contains(fn ($c) => ($c['via_transfer'] ?? false) === true),
            'el ruteo a transferencia es solo para formalizados',
        );
        $this->assertGreaterThan(0.0, (float) $entry->cash_amount, 'el de efectivo cobra el concepto en efectivo');
    }
}
