<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\CompensationRateResolverService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * REGLA (Luis 2026-11-25): nadie cobra el MISMO concepto dos veces el mismo
 * día. La captura ya lo bloqueaba; ahora también la aprobación, el cambio de
 * fecha y, como red final, la nómina (paga la primera y avisa la repetida).
 */
class SameDayDuplicateConceptTest extends FeatureTestCase
{
    private function bono(): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Premio',
            'code' => 'PREMIO-'.uniqid(),
            'calculation_type' => 'fixed',
            'fixed_amount' => 1,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => 'special',
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_active' => true,
        ]);
    }

    private function auth(Employee $employee, CompensationType $type, string $date, float $qty, string $status = Authorization::STATUS_APPROVED): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $type->id,
            'date' => $date,
            'hours' => $qty,
            'status' => $status,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    private function pay(Employee $employee, array $auths): array
    {
        $employee->load('compensationTypes');

        return app(CompensationRateResolverService::class)->calculateAllCompensation(
            $employee,
            [],
            100.00,
            800.00,
            new Collection(array_map(fn ($a) => $a->load('compensationType'), $auths)),
        );
    }

    /** @return array{0: \App\Models\User, 1: string} */
    private function adminWithCode(): array
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $secret = (new Google2FA)->generateSecretKey();
        $user->twoFactorDevices()->create([
            'name' => 'TestDevice',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);

        return [$user, (new Google2FA)->getCurrentOtp($secret)];
    }

    public function test_payroll_pays_same_concept_once_per_day_and_reports_the_repeat(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->bono();
        $first = $this->auth($employee, $type, '2026-11-20', 4200);
        $repeat = $this->auth($employee, $type, '2026-11-20', 4200);

        $result = $this->pay($employee, [$first, $repeat]);

        $this->assertEqualsWithDelta(4200.00, $result['total'], 0.01, 'el repetido no se paga');
        $this->assertCount(1, $result['duplicates']);
        $this->assertSame($repeat->id, $result['duplicates'][0]['authorization_id']);
        $this->assertSame($first->id, $result['duplicates'][0]['paid_authorization_id']);
    }

    public function test_same_concept_on_different_days_still_pays_both(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->bono();

        $result = $this->pay($employee, [
            $this->auth($employee, $type, '2026-11-20', 100),
            $this->auth($employee, $type, '2026-11-21', 100),
        ]);

        $this->assertEqualsWithDelta(200.00, $result['total'], 0.01);
        $this->assertSame([], $result['duplicates']);
    }

    public function test_different_concepts_same_day_both_pay(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);

        $result = $this->pay($employee, [
            $this->auth($employee, $this->bono(), '2026-11-20', 100),
            $this->auth($employee, $this->bono(), '2026-11-20', 50),
        ]);

        $this->assertEqualsWithDelta(150.00, $result['total'], 0.01);
        $this->assertSame([], $result['duplicates']);
    }

    public function test_approving_a_repeat_of_an_approved_concept_is_blocked(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->bono();
        $this->auth($employee, $type, '2026-11-20', 300);
        $repeat = $this->auth($employee, $type, '2026-11-20', 300, Authorization::STATUS_PENDING);

        [$admin, $code] = $this->adminWithCode();
        $this->actingAs($admin)
            ->post(route('authorizations.approve', $repeat), ['two_factor_code' => $code])
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'dos veces el mismo día'));

        $this->assertSame(Authorization::STATUS_PENDING, $repeat->fresh()->status);
    }

    public function test_moving_a_concept_onto_a_day_that_already_has_it_is_blocked(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->bono();
        $this->auth($employee, $type, '2026-11-20', 300);
        $other = $this->auth($employee, $type, '2026-11-19', 300);

        $this->actingAsAdmin();
        $this->post(route('authorizations.updateDate', $other), ['date' => '2026-11-20'])
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'No se cambió la fecha'));

        $this->assertSame('2026-11-19', $other->fresh()->date->toDateString());
    }
}
