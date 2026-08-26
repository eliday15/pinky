<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use App\Services\PayrollInvalidationService;
use Tests\FeatureTestCase;

/**
 * PAGO UNIFICADO (Elias 2026-08-25): el mes ya no se paga aparte.
 *
 * Al generar la nómina MENSUAL, sus extras se pegan a la nómina SEMANAL que se
 * paga el mismo día: un solo periodo, un solo recibo, un solo pago. El dinero es
 * exactamente el mismo que cuando eran dos nóminas separadas. Los departamentos
 * con nómina propia (Taller) no llevan mensual: se quedan solo con su semana.
 */
class UnifiedPayrollPeriodTest extends FeatureTestCase
{
    /** Semana del pago: lunes 17 ago → domingo 23 ago 2026. */
    private const WEEK_START = '2026-08-17';

    private const WEEK_END = '2026-08-23';

    /** Mes de extras: 27 jul → 23 ago 2026 (4 semanas, mismo día de pago). */
    private const MONTH_START = '2026-07-27';

    private const MONTH_END = '2026-08-23';

    private const PAYMENT_DATE = '2026-08-24';

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
        ]);
    }

    /**
     * Un día trabajado con tiempo extra autorizado. El TE solo lo paga quien
     * paga extras; el sueldo del día, quien paga base.
     */
    private function workedDay(Employee $employee, string $date, float $overtime = 0.0): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => $date,
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => $overtime,
            'overtime_authorized_hours' => $overtime,
        ]);
    }

    /** Asistencia del mes completo: un día con TE en cada una de las 4 semanas. */
    private function monthOfWork(Employee $employee): void
    {
        foreach (['2026-07-29', '2026-08-05', '2026-08-12', '2026-08-19'] as $date) {
            $this->workedDay($employee, $date, 2.00);
        }
    }

    private function weeklyPeriod(array $attributes = []): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create(array_merge([
            'name' => 'Semana 17 ago - 23 ago',
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
            'payment_date' => self::PAYMENT_DATE,
            'status' => 'review',
        ], $attributes));
    }

    private function postMonthly(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('payroll.store'), array_merge([
            'name' => 'Mes 27 jul - 23 ago',
            'type' => 'monthly',
            'start_date' => self::MONTH_START,
            'end_date' => self::MONTH_END,
            'payment_date' => self::PAYMENT_DATE,
        ], $extra));
    }

    /** El alta mensual del formulario: además del mes manda la semana que se paga con él. */
    private function postMonthlyWithWeek(): \Illuminate\Testing\TestResponse
    {
        return $this->postMonthly([
            'week_start_date' => self::WEEK_START,
            'week_end_date' => self::WEEK_END,
        ]);
    }

    public function test_generating_the_monthly_unifies_it_into_the_week_paid_the_same_day(): void
    {
        $employee = $this->employee();
        $this->monthOfWork($employee);
        $week = $this->weeklyPeriod();
        $this->calculator()->calculatePeriod($week);
        $this->actingAsAdmin();

        $this->postMonthly()->assertSessionHasNoErrors();

        $this->assertSame(0, PayrollPeriod::where('type', 'monthly')->count(), 'el mes ya no crea su propio periodo');
        $this->assertSame(1, PayrollPeriod::count(), 'sigue habiendo una sola nómina');

        $week->refresh();
        $this->assertTrue($week->isUnified(), 'la semana quedó unificada');
        $this->assertSame(self::MONTH_START, $week->extras_start_date->toDateString());
        $this->assertSame(self::MONTH_END, $week->extras_end_date->toDateString());
        $this->assertTrue($week->paysBase() && $week->paysExtras(), 'paga base Y extras');

        $entry = $week->entries()->where('employee_id', $employee->id)->firstOrFail();
        // Sueldo de la semana (800 × 7) + TE del MES (4 días × 2 h × 100 × 1.5).
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'paga la semana completa');
        $this->assertEqualsWithDelta(1200.00, (float) $entry->overtime_pay, 0.01, 'paga el TE de las 4 semanas del mes');
        $this->assertEqualsWithDelta(8.00, (float) $entry->overtime_authorized_hours, 0.01);
    }

    public function test_unified_period_pays_exactly_the_same_money_as_the_two_separate_ones(): void
    {
        $employee = $this->employee();
        $this->monthOfWork($employee);

        // Como antes: semanal (base) + mensual (extras), dos pagos.
        $week = $this->weeklyPeriod();
        $month = PayrollPeriod::factory()->monthly()->create([
            'start_date' => self::MONTH_START,
            'end_date' => self::MONTH_END,
            'payment_date' => self::PAYMENT_DATE,
        ]);
        $weekEntry = $this->calculator()->calculateEmployeePayroll($week, $employee);
        $monthEntry = $this->calculator()->calculateEmployeePayroll($month, $employee);

        // Ahora: un solo periodo unificado.
        $unified = PayrollPeriod::factory()->unified(self::MONTH_START, self::MONTH_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
            'payment_date' => self::PAYMENT_DATE,
        ]);
        $unifiedEntry = $this->calculator()->calculateEmployeePayroll($unified, $employee->fresh());

        $fields = [
            'regular_pay', 'overtime_pay', 'velada_pay', 'holiday_pay', 'weekend_pay',
            'other_compensation_pay', 'vacation_pay', 'vacation_premium_pay', 'sick_leave_pay',
            'punctuality_bonus', 'bonuses', 'deductions', 'gross_pay', 'net_pay',
            'cash_amount', 'bank_amount', 'isr_amount', 'imss_amount',
            'regular_hours', 'overtime_hours', 'overtime_authorized_hours',
            'days_worked', 'days_absent', 'velada_days',
        ];

        foreach ($fields as $field) {
            $this->assertEqualsWithDelta(
                (float) $weekEntry->$field + (float) $monthEntry->$field,
                (float) $unifiedEntry->$field,
                0.01,
                "el unificado paga lo mismo que las dos nóminas juntas en {$field}",
            );
        }

        // El desglose conserva de dónde salió cada cosa.
        $scope = $unifiedEntry->calculation_breakdown['scope'];
        $this->assertTrue($scope['unified']);
        $this->assertSame(self::MONTH_START, $scope['extras_range']['start']);
        $this->assertSame(self::WEEK_START, $scope['base_range']['start']);
    }

    public function test_batch_calculation_of_a_unified_period_matches_the_single_employee_path(): void
    {
        // calculatePeriod precarga en lote los datos de CADA rango; el resultado
        // debe ser idéntico al del camino de un solo empleado (sin contexto).
        $employee = $this->employee();
        $this->monthOfWork($employee);

        $batch = PayrollPeriod::factory()->unified(self::MONTH_START, self::MONTH_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);
        $this->calculator()->calculatePeriod($batch);
        $batchEntry = $batch->entries()->where('employee_id', $employee->id)->firstOrFail();

        $single = PayrollPeriod::factory()->unified(self::MONTH_START, self::MONTH_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);
        $singleEntry = $this->calculator()->calculateEmployeePayroll($single, $employee->fresh());

        foreach (['regular_pay', 'overtime_pay', 'gross_pay', 'net_pay', 'cash_amount', 'days_worked'] as $field) {
            $this->assertEqualsWithDelta(
                (float) $singleEntry->$field,
                (float) $batchEntry->$field,
                0.01,
                "el cálculo en lote coincide con el individual en {$field}",
            );
        }
    }

    public function test_monthly_creates_the_week_already_unified_when_it_does_not_exist_yet(): void
    {
        // Caso real (Elias 2026-08-26): borraron la semana y generaron el mes.
        // El alta manda el rango de la semana, así que la nómina nace UNIFICADA
        // (sueldo de la semana + extras del mes) en vez de una mensual suelta
        // que dejaría todo en efectivo y $0 en transferencia.
        $employee = $this->employee();
        $this->monthOfWork($employee);
        $this->actingAsAdmin();

        $this->postMonthlyWithWeek()->assertSessionHasNoErrors();

        $this->assertSame(0, PayrollPeriod::where('type', 'monthly')->count(), 'no nace una mensual suelta');
        $period = PayrollPeriod::whereNull('department_id')->firstOrFail();
        $this->assertTrue($period->isUnified());
        $this->assertSame(self::WEEK_START, $period->start_date->toDateString(), 'la base corre sobre la semana');
        $this->assertSame(self::MONTH_START, $period->extras_start_date->toDateString());
        $this->assertSame('Semana 17 ago - 23 ago', $period->name, 'se nombra como la semana que es');

        $entry = $period->entries()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'sí paga sueldo base');
        $this->assertEqualsWithDelta(1200.00, (float) $entry->overtime_pay, 0.01, 'y los extras del mes');
    }

    public function test_monthly_also_generates_the_week_for_departments_with_their_own_payroll(): void
    {
        // Taller no lleva extras del mes, pero SÍ tiene que salir su semana en
        // el mismo alta: antes se quedaba sin nómina.
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller Adriana']);
        $tallerEmployee = Employee::factory()->create([
            'status' => 'active', 'daily_salary' => 800.00, 'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01', 'department_id' => $taller->id,
        ]);
        $this->monthOfWork($tallerEmployee);
        $general = $this->employee();
        $this->monthOfWork($general);
        $this->actingAsAdmin();

        $this->postMonthlyWithWeek()->assertSessionHasNoErrors();

        $tallerPeriod = PayrollPeriod::where('department_id', $taller->id)->firstOrFail();
        $this->assertSame('weekly', $tallerPeriod->type);
        $this->assertFalse($tallerPeriod->isUnified(), 'Taller no lleva extras del mes');
        $this->assertSame('Semana 17 ago - 23 ago - Taller Adriana', $tallerPeriod->name);
        $this->assertSame(self::WEEK_START, $tallerPeriod->start_date->toDateString());

        $entry = $tallerPeriod->entries()->where('employee_id', $tallerEmployee->id)->firstOrFail();
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'Taller cobra su semana');
        $this->assertEqualsWithDelta(0.00, (float) $entry->overtime_pay, 0.01, 'sin extras del mes');
    }

    public function test_monthly_with_week_range_still_unifies_into_an_existing_week(): void
    {
        // Si la semana YA existe manda ella: no se duplica ni se crea otra.
        $employee = $this->employee();
        $this->monthOfWork($employee);
        $week = $this->weeklyPeriod();
        $this->actingAsAdmin();

        $this->postMonthlyWithWeek()->assertSessionHasNoErrors();

        $this->assertSame(1, PayrollPeriod::count(), 'sigue habiendo una sola nómina general');
        $this->assertTrue($week->refresh()->isUnified());
    }

    public function test_monthly_does_not_touch_departments_with_their_own_payroll(): void
    {
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $tallerEmployee = Employee::factory()->create([
            'status' => 'active', 'daily_salary' => 800.00, 'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01', 'department_id' => $taller->id,
        ]);
        $this->monthOfWork($tallerEmployee);

        $general = $this->weeklyPeriod();
        $tallerWeek = $this->weeklyPeriod(['name' => 'Semana - Taller', 'department_id' => $taller->id]);
        $this->actingAsAdmin();

        $this->postMonthly()->assertSessionHasNoErrors();

        $tallerWeek->refresh();
        $this->assertFalse($tallerWeek->isUnified(), 'Taller no lleva extras del mes: solo su semana');
        $this->assertSame(
            0,
            PayrollPeriod::where('department_id', $taller->id)->where('type', 'monthly')->count(),
            'Taller no genera nómina mensual',
        );
        $this->assertTrue($general->refresh()->isUnified(), 'la general sí se unifica');

        // Y su recibo sigue pagando solo la semana, sin el TE del mes.
        $this->calculator()->calculatePeriod($tallerWeek->refresh());
        $entry = $tallerWeek->entries()->where('employee_id', $tallerEmployee->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.00, (float) $entry->overtime_pay, 0.01, 'Taller no paga extras del mes');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'Taller paga su semana');
    }

    public function test_creating_the_week_absorbs_a_monthly_that_was_created_first(): void
    {
        $employee = $this->employee();
        $this->monthOfWork($employee);

        $month = PayrollPeriod::factory()->monthly()->create([
            'name' => 'Mes 27 jul - 23 ago',
            'start_date' => self::MONTH_START,
            'end_date' => self::MONTH_END,
            'payment_date' => self::PAYMENT_DATE,
            'status' => 'review',
        ]);
        $this->calculator()->calculatePeriod($month);
        $this->actingAsAdmin();

        $this->post(route('payroll.store'), [
            'name' => 'Semana 17 ago - 23 ago',
            'type' => 'weekly',
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
            'payment_date' => self::PAYMENT_DATE,
        ])->assertSessionHasNoErrors();

        $this->assertNull(PayrollPeriod::find($month->id), 'el mensual suelto se absorbió');
        $this->assertSame(0, PayrollEntry::where('payroll_period_id', $month->id)->count(), 'sus recibos se fueron con él');

        $week = PayrollPeriod::where('type', 'weekly')->firstOrFail();
        $this->assertTrue($week->isUnified());
        $entry = $week->entries()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(1200.00, (float) $entry->overtime_pay, 0.01);
    }

    public function test_monthly_keeps_its_own_period_when_the_week_is_already_approved(): void
    {
        // Una semana ya aprobada no se toca: sus montos ya se contaron. El mes
        // se genera aparte, como antes, en vez de quedarse sin pagar.
        $employee = $this->employee();
        $this->monthOfWork($employee);
        $this->weeklyPeriod(['status' => 'approved']);
        $this->actingAsAdmin();

        $this->postMonthly()->assertSessionHasNoErrors();

        $month = PayrollPeriod::where('type', 'monthly')->first();
        $this->assertNotNull($month, 'se crea la mensual por separado');
        $this->assertFalse($month->isUnified());
        $entry = $month->entries()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(1200.00, (float) $entry->overtime_pay, 0.01, 'los extras del mes se pagan igual');
        $this->assertEqualsWithDelta(0.00, (float) $entry->regular_pay, 0.01, 'sin sueldo base: eso ya lo pagó la semana');
    }

    public function test_generating_the_monthly_twice_does_not_double_the_extras(): void
    {
        $this->employee();
        $week = $this->weeklyPeriod();
        $this->actingAsAdmin();

        $this->postMonthly()->assertSessionHasNoErrors();
        $this->postMonthly()->assertSessionHasErrors('start_date');

        $this->assertSame(0, PayrollPeriod::where('type', 'monthly')->count(), 'no se cuela una mensual duplicada');
        $this->assertSame(1, PayrollPeriod::count());
        $this->assertTrue($week->refresh()->isUnified());
    }

    public function test_an_existing_monthly_can_be_unified_from_its_own_page(): void
    {
        // Para las nóminas que YA se generaron por separado: el botón "Unificar
        // con la semana" las junta sin tener que borrar y recapturar.
        $employee = $this->employee();
        $this->monthOfWork($employee);
        $week = $this->weeklyPeriod();
        $month = PayrollPeriod::factory()->monthly()->create([
            'name' => 'Mes 27 jul - 23 ago',
            'start_date' => self::MONTH_START,
            'end_date' => self::MONTH_END,
            'payment_date' => self::PAYMENT_DATE,
            'status' => 'review',
        ]);
        $this->calculator()->calculatePeriod($month);
        $this->actingAsAdmin();

        // La página del mensual ofrece la semana con la que se puede unificar.
        $this->get(route('payroll.show', $month))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unifiableWeek.id', $week->id));

        $this->post(route('payroll.unify', $month))
            ->assertRedirect(route('payroll.show', $week));

        $this->assertNull(PayrollPeriod::find($month->id), 'la mensual se fue');
        $week->refresh();
        $this->assertTrue($week->isUnified());

        $entry = $week->entries()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(1200.00, (float) $entry->overtime_pay, 0.01);
    }

    public function test_unify_button_is_hidden_when_the_week_cannot_be_touched(): void
    {
        $this->employee();
        $this->weeklyPeriod(['status' => 'paid']);
        $month = PayrollPeriod::factory()->monthly()->create([
            'start_date' => self::MONTH_START,
            'end_date' => self::MONTH_END,
            'status' => 'review',
        ]);
        $this->actingAsAdmin();

        $this->get(route('payroll.show', $month))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unifiableWeek', null));

        $this->post(route('payroll.unify', $month))->assertSessionHas('error');
        $this->assertNotNull(PayrollPeriod::find($month->id), 'la mensual sigue ahí');
    }

    public function test_a_legacy_biweekly_period_is_never_unified(): void
    {
        // La quincenal ya paga base Y extras: pegarle el mes le haría pagar los
        // extras dos veces. Se queda como está y el mes se genera aparte.
        $employee = $this->employee();
        $this->monthOfWork($employee);
        $biweekly = PayrollPeriod::factory()->biweekly()->create([
            'start_date' => '2026-08-10',
            'end_date' => self::WEEK_END,
            'payment_date' => self::PAYMENT_DATE,
            'status' => 'review',
        ]);
        $this->actingAsAdmin();

        $this->postMonthly()->assertSessionHasNoErrors();

        $this->assertFalse($biweekly->refresh()->isUnified(), 'la quincenal no absorbe extras');
        $this->assertSame(1, PayrollPeriod::where('type', 'monthly')->count(), 'el mes se genera aparte');
    }

    public function test_salary_concept_is_not_paid_on_top_of_the_base_salary(): void
    {
        // El sueldo del personal en periodo de prueba se captura como concepto y
        // caía en la mensual. Al unificar, el concepto ya no se paga encima del
        // sueldo base: manda el sueldo base.
        $employee = $this->employee();
        $salary = CompensationType::factory()->fixed(3500.00)->create([
            'name' => 'Sueldo periodo de prueba',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_MONTHLY,
            'is_recurring' => true,
            'is_base_salary_concept' => true,
        ]);
        $employee->compensationTypes()->attach($salary->id, ['is_active' => true]);

        $unified = PayrollPeriod::factory()->unified(self::MONTH_START, self::MONTH_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($unified, $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'cobra su sueldo base');
        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01, 'el concepto de sueldo NO se paga otra vez');
        $suppressed = $entry->calculation_breakdown['suppressed_base_salary_concepts'] ?? [];
        $this->assertCount(1, $suppressed, 'queda registrado por qué no se pagó');
        $this->assertEqualsWithDelta(3500.00, (float) $suppressed[0]['amount'], 0.01);
    }

    public function test_salary_concept_still_pays_when_the_employee_has_no_base_salary(): void
    {
        // Quien NO cobra sueldo base (su sueldo ES el concepto) lo sigue
        // cobrando: la regla solo evita el doble pago.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 0,
            'hourly_rate' => 0,
            'hire_date' => '2025-01-01',
        ]);
        $salary = CompensationType::factory()->fixed(3500.00)->create([
            'name' => 'Sueldo periodo de prueba',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_MONTHLY,
            'is_recurring' => true,
            'is_base_salary_concept' => true,
        ]);
        $employee->compensationTypes()->attach($salary->id, ['is_active' => true]);

        $unified = PayrollPeriod::factory()->unified(self::MONTH_START, self::MONTH_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($unified, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(3500.00, (float) $entry->other_compensation_pay, 0.01, 'sin base, el concepto sigue siendo su pago');
    }

    public function test_a_normal_monthly_period_still_pays_a_salary_concept(): void
    {
        // Nada cambia para las nóminas que NO son unificadas: la mensual suelta
        // sigue pagando el concepto igual que siempre.
        $employee = $this->employee();
        $salary = CompensationType::factory()->fixed(3500.00)->create([
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_MONTHLY,
            'is_recurring' => true,
            'is_base_salary_concept' => true,
        ]);
        $employee->compensationTypes()->attach($salary->id, ['is_active' => true]);

        $month = PayrollPeriod::factory()->monthly()->create([
            'start_date' => self::MONTH_START,
            'end_date' => self::MONTH_END,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($month, $employee);

        $this->assertEqualsWithDelta(3500.00, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_a_change_inside_the_extras_range_flags_the_unified_period(): void
    {
        // Un cambio del MES que cae fuera de la semana también tiene que marcar
        // la nómina unificada para recalcular: ella es quien paga esos extras.
        $employee = $this->employee();
        $unified = PayrollPeriod::factory()->unified(self::MONTH_START, self::MONTH_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
            'status' => 'review',
        ]);

        app(PayrollInvalidationService::class)->invalidate($employee->id, '2026-08-05');

        $this->assertTrue($unified->refresh()->requires_recalculation, 'la unificada se marca para recalcular');
    }
}
