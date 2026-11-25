<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\FeatureTestCase;

/**
 * PRENÓMINA (Luis 2026-11-25): lo que va a cobrar cada quien antes de generar
 * la nómina, con el mismo cálculo, sin guardar nada. Quien no ve dinero ve los
 * conceptos sin un solo importe.
 */
class PrenominaTest extends FeatureTestCase
{
    private const WEEK_START = '2026-08-17';

    private const WEEK_END = '2026-08-23';

    private function employeeWithBono(float $qty = 500): array
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-18',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);
        $bono = CompensationType::factory()->create([
            'name' => 'Premio',
            'code' => 'PREMIO-'.uniqid(),
            'calculation_type' => 'fixed',
            'fixed_amount' => 1,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => 'special',
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_active' => true,
        ]);
        $employee->compensationTypes()->attach($bono->id, ['is_active' => true]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bono->id,
            'date' => '2026-08-19',
            'hours' => $qty,
            'status' => Authorization::STATUS_APPROVED,
            'start_time' => null,
            'end_time' => null,
        ]);

        return [$employee, $bono];
    }

    private function query(array $extra = []): array
    {
        return array_merge([
            'week_start' => self::WEEK_START,
            'week_end' => self::WEEK_END,
            'without_extras' => 1,
        ], $extra);
    }

    public function test_admin_sees_what_each_person_will_be_paid_and_nothing_is_saved(): void
    {
        [$employee] = $this->employeeWithBono(500);
        $this->actingAsAdmin();
        $periodsBefore = PayrollPeriod::count();

        $this->get(route('payroll.prenomina', $this->query()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Prenomina')
                ->where('withAmounts', true)
                ->missing('result')
                ->loadDeferredProps(fn ($reload) => $reload->where('result.rows', function ($rows) use ($employee) {
                    $row = collect($rows)->firstWhere('employee_id', $employee->id);
                    $premio = collect($row['weekly']['lines'])->firstWhere('label', 'Premio');

                    return $premio !== null
                        && abs($premio['amount'] - 500) < 0.01
                        && $premio['detail'] === '500 unidades'
                        && $row['total'] > 500;
                })));

        $this->assertSame($periodsBefore, PayrollPeriod::count(), 'la prenómina no deja periodos');
        $this->assertSame(0, PayrollEntry::count(), 'ni recibos');
    }

    public function test_user_without_money_permission_gets_concepts_without_any_amount(): void
    {
        [$employee] = $this->employeeWithBono(500);
        $user = $this->createUser('rrhh');
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'payroll.preview', 'guard_name' => 'web']));
        $this->actingAs($user);

        $this->get(route('payroll.prenomina', $this->query()))->assertOk()
            ->assertInertia(fn ($page) => $page->where('withAmounts', false));

        // La prop diferida se pide como lo hace el navegador (recarga parcial).
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $response = $this->get(route('payroll.prenomina', $this->query()), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Payroll/Prenomina',
            'X-Inertia-Partial-Data' => 'result',
        ])->assertOk();
        $result = $response->json('props.result');

        $this->assertNull($result['totals']);
        $row = collect($result['rows'])->firstWhere('employee_id', $employee->id);
        $premio = collect($row['weekly']['lines'])->firstWhere('label', 'Premio');
        $this->assertNotNull($premio);
        $this->assertArrayNotHasKey('amount', $premio);
        $this->assertArrayNotHasKey('total', $row);

        // Ningún importe viaja a la pantalla: ni el monto del bono ni el sueldo.
        $props = json_encode($result['rows']);
        $this->assertStringNotContainsString('500', str_replace('500 unidades', '', $props));
        $this->assertStringNotContainsString('"net"', $props);
        $this->assertStringNotContainsString('"cash"', $props);
    }

    public function test_repeated_concept_same_day_is_flagged(): void
    {
        [$employee, $bono] = $this->employeeWithBono(300);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bono->id,
            'date' => '2026-08-19',
            'hours' => 300,
            'status' => Authorization::STATUS_APPROVED,
            'start_time' => null,
            'end_time' => null,
        ]);
        $this->actingAsAdmin();

        $this->get(route('payroll.prenomina', $this->query()))
            ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->where('result.rows', function ($rows) use ($employee) {
                $row = collect($rows)->firstWhere('employee_id', $employee->id);
                $premio = collect($row['weekly']['lines'])->where('label', 'Premio');

                return $premio->count() === 1
                    && str_contains(implode(' ', $row['alerts']), 'capturado dos veces');
            })));
    }

    public function test_supervisor_cannot_open_prenomina(): void
    {
        $this->actingAsSupervisor();

        $this->get(route('payroll.prenomina', $this->query()))->assertForbidden();
    }

    public function test_default_range_is_the_week_after_the_last_payroll(): void
    {
        PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-16',
            'payment_date' => '2026-08-17',
            'status' => 'paid',
            'created_by' => User::factory()->create()->id,
        ]);
        $this->actingAsAdmin();

        $this->get(route('payroll.prenomina'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.week_start', '2026-08-17')
                ->where('filters.week_end', '2026-08-23'));
    }
}
