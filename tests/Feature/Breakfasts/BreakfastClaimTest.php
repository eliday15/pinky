<?php

namespace Tests\Feature\Breakfasts;

use App\Models\BreakfastClaim;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\SystemSetting;
use App\Services\BreakfastClaimService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\FeatureTestCase;

/**
 * Feature tests for the breakfast kiosk claim rules.
 *
 * The window is [entry_time - window_minutes, entry_time): an employee whose
 * shift starts at 09:00 gets a breakfast at 08:50 but NOT at 09:00 sharp.
 * One breakfast per day, hard-gated server-side by the breakfast PIN and the
 * configured face-match threshold.
 */
class BreakfastClaimTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Valores explícitos (las filas existen por la migración seed; set()
        // además limpia el cache de settings entre tests).
        SystemSetting::set('breakfast_cost', 30);
        SystemSetting::set('breakfast_window_minutes', 60);
        SystemSetting::set('breakfast_face_max_distance', 0.5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): BreakfastClaimService
    {
        return app(BreakfastClaimService::class);
    }

    /**
     * Active employee on a Mon-Fri schedule entering at 09:00, with photo
     * and breakfast PIN '1234'.
     */
    private function makeEmployee(array $attributes = []): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '09:00',
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'schedule_id' => $schedule->id,
            'photo_path' => 'employees/photos/test.jpg',
            'breakfast_pin' => '1234',
        ], $attributes));
    }

    private function claimAt(Employee $employee, string $dateTime): BreakfastClaim
    {
        Carbon::setTestNow(Carbon::parse($dateTime));

        return $this->service()->validateAndCreate(
            $employee,
            '1234',
            0.35,
            null,
            $this->adminUser(),
        );
    }

    private function assertClaimFails(Employee $employee, string $dateTime, string $needle): void
    {
        try {
            $this->claimAt($employee, $dateTime);
            $this->fail('Expected the claim to be rejected: '.$needle);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, collect($e->errors())->flatten()->implode(' '));
        }
    }

    // ------------------------------------------------------------------
    // Ventana antes de la hora de entrada (miércoles 2026-06-03, entrada 09:00)
    // ------------------------------------------------------------------

    public function test_claim_at_ten_minutes_before_entry_is_accepted(): void
    {
        $employee = $this->makeEmployee();

        $claim = $this->claimAt($employee, '2026-06-03 08:50:00');

        $this->assertDatabaseHas('breakfast_claims', [
            'id' => $claim->id,
            'employee_id' => $employee->id,
            'claim_date' => '2026-06-03',
        ]);
    }

    public function test_claim_at_window_start_is_accepted(): void
    {
        $employee = $this->makeEmployee();

        $claim = $this->claimAt($employee, '2026-06-03 08:00:00');

        $this->assertSame('2026-06-03', $claim->claim_date->format('Y-m-d'));
    }

    public function test_claim_before_window_start_is_rejected(): void
    {
        $employee = $this->makeEmployee();

        $this->assertClaimFails($employee, '2026-06-03 07:59:00', 'temprano');
    }

    public function test_claim_at_entry_time_sharp_is_rejected(): void
    {
        $employee = $this->makeEmployee();

        $this->assertClaimFails($employee, '2026-06-03 09:00:00', 'Fuera de horario');
    }

    public function test_claim_after_entry_time_is_rejected(): void
    {
        $employee = $this->makeEmployee();

        $this->assertClaimFails($employee, '2026-06-03 09:01:00', 'Fuera de horario');
    }

    public function test_window_respects_configured_minutes(): void
    {
        SystemSetting::set('breakfast_window_minutes', 15);
        $employee = $this->makeEmployee();

        $this->assertClaimFails($employee, '2026-06-03 08:30:00', 'temprano');

        $claim = $this->claimAt($employee, '2026-06-03 08:50:00');
        $this->assertNotNull($claim->id);
    }

    public function test_schedule_override_entry_time_moves_the_window(): void
    {
        // Override por empleado: entra a las 07:00 los miércoles → a las 08:50
        // ya está fuera de ventana aunque el horario base diga 09:00.
        $employee = $this->makeEmployee([
            'schedule_overrides' => [
                'day_schedules' => ['wednesday' => ['entry_time' => '07:00']],
            ],
        ]);

        $this->assertClaimFails($employee, '2026-06-03 08:50:00', 'Fuera de horario');

        $claim = $this->claimAt($employee, '2026-06-03 06:30:00');
        $this->assertNotNull($claim->id);
    }

    // ------------------------------------------------------------------
    // Días sin ventana
    // ------------------------------------------------------------------

    public function test_non_working_day_is_rejected(): void
    {
        $employee = $this->makeEmployee();

        // 2026-06-07 es domingo, fuera de un horario Lun-Vie.
        $this->assertClaimFails($employee, '2026-06-07 08:30:00', 'no es un día laborable');
    }

    // ------------------------------------------------------------------
    // Reglas duras: 1 por día, NIP, estado, rostro, foto
    // ------------------------------------------------------------------

    public function test_second_claim_same_day_is_rejected(): void
    {
        $employee = $this->makeEmployee();

        $this->claimAt($employee, '2026-06-03 08:10:00');
        $this->assertClaimFails($employee, '2026-06-03 08:40:00', 'Ya cobraste');
        $this->assertSame(1, BreakfastClaim::count());
    }

    public function test_claim_next_day_is_accepted_again(): void
    {
        $employee = $this->makeEmployee();

        $this->claimAt($employee, '2026-06-03 08:10:00');
        $this->claimAt($employee, '2026-06-04 08:10:00');

        $this->assertSame(2, BreakfastClaim::count());
    }

    public function test_wrong_pin_is_rejected(): void
    {
        $employee = $this->makeEmployee();
        Carbon::setTestNow(Carbon::parse('2026-06-03 08:30:00'));

        try {
            $this->service()->validateAndCreate($employee, '9999', 0.35, null, $this->adminUser());
            $this->fail('Expected wrong PIN to be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pin', $e->errors());
        }
        $this->assertSame(0, BreakfastClaim::count());
    }

    public function test_employee_without_pin_is_rejected(): void
    {
        $employee = $this->makeEmployee(['breakfast_pin' => null]);

        $this->assertClaimFails($employee, '2026-06-03 08:30:00', 'NIP de desayunos');
    }

    public function test_inactive_employee_is_rejected(): void
    {
        $employee = $this->makeEmployee(['status' => 'inactive']);

        $this->assertClaimFails($employee, '2026-06-03 08:30:00', 'no está activo');
    }

    public function test_employee_without_photo_is_rejected(): void
    {
        $employee = $this->makeEmployee(['photo_path' => null]);

        $this->assertClaimFails($employee, '2026-06-03 08:30:00', 'foto');
    }

    public function test_face_distance_above_threshold_is_rejected(): void
    {
        $employee = $this->makeEmployee();
        Carbon::setTestNow(Carbon::parse('2026-06-03 08:30:00'));

        try {
            $this->service()->validateAndCreate($employee, '1234', 0.72, null, $this->adminUser());
            $this->fail('Expected face distance above threshold to be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('face', $e->errors());
        }
    }

    public function test_unit_cost_snapshot_uses_current_setting(): void
    {
        SystemSetting::set('breakfast_cost', 42.50);
        $employee = $this->makeEmployee();

        $claim = $this->claimAt($employee, '2026-06-03 08:30:00');

        $this->assertEqualsWithDelta(42.50, (float) $claim->unit_cost, 0.001);

        // Cambiar el precio después NO altera el snapshot ya cobrado.
        SystemSetting::set('breakfast_cost', 99);
        $this->assertEqualsWithDelta(42.50, (float) $claim->fresh()->unit_cost, 0.001);
    }

    public function test_breakfast_pin_is_hashed_and_hidden(): void
    {
        $employee = $this->makeEmployee();

        $this->assertTrue($employee->hasBreakfastPin());
        $this->assertTrue($employee->verifyBreakfastPin('1234'));
        $this->assertFalse($employee->verifyBreakfastPin('0000'));
        $this->assertNotSame('1234', $employee->getAttributes()['breakfast_pin']);
        $this->assertArrayNotHasKey('breakfast_pin', $employee->toArray());

        // Update sin tocar el NIP no lo borra.
        $employee->update(['breakfast_pin' => '', 'first_name' => 'Nuevo']);
        $this->assertTrue($employee->fresh()->verifyBreakfastPin('1234'));
    }

    // ------------------------------------------------------------------
    // Endpoints HTTP (kiosco)
    // ------------------------------------------------------------------

    public function test_kiosk_endpoints_require_register_permission(): void
    {
        $this->actingAsEmployee();

        $this->get(route('breakfasts.kiosk'))->assertForbidden();
        $this->postJson(route('breakfasts.lookup'), ['employee_number' => 'X'])->assertForbidden();
        $this->postJson(route('breakfasts.store'), [])->assertForbidden();
    }

    public function test_index_requires_view_permission(): void
    {
        $this->actingAsEmployee();

        $this->get(route('breakfasts.index'))->assertForbidden();
    }

    public function test_lookup_returns_employee_and_eligibility(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 08:30:00'));
        $employee = $this->makeEmployee();
        $this->actingAsRrhh();

        $this->postJson(route('breakfasts.lookup'), ['employee_number' => $employee->employee_number])
            ->assertOk()
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('status.eligible', true)
            ->assertJsonPath('status.window.end', '09:00');
    }

    public function test_lookup_unknown_employee_returns_validation_error(): void
    {
        $this->actingAsRrhh();

        $this->postJson(route('breakfasts.lookup'), ['employee_number' => 'NOPE-404'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_number']);
    }

    public function test_store_creates_claim_via_http(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 08:30:00'));
        $employee = $this->makeEmployee();
        $this->actingAsRrhh();

        $this->postJson(route('breakfasts.store'), [
            'employee_id' => $employee->id,
            'pin' => '1234',
            'face_distance' => 0.31,
        ])
            ->assertCreated()
            ->assertJsonPath('claim.employee_name', $employee->full_name);

        $this->assertDatabaseHas('breakfast_claims', [
            'employee_id' => $employee->id,
            'claim_date' => '2026-06-03',
        ]);
    }

    public function test_store_rejects_wrong_pin_via_http(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 08:30:00'));
        $employee = $this->makeEmployee();
        $this->actingAsRrhh();

        $this->postJson(route('breakfasts.store'), [
            'employee_id' => $employee->id,
            'pin' => '9999',
            'face_distance' => 0.31,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }
}
