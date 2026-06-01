<?php

namespace App\Console\Commands;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Services\CompensationRateResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * End-to-end verification that explicit Authorization.compensation_type_id
 * routes through the right comp_type rate (HE / HED / HET / VEL / etc.).
 */
class TestPayrollCompensation extends Command
{
    protected $signature = 'payroll:test-compensation';

    protected $description = 'Verify HE/HED/HET tier routing and explicit auth comp_type honor in payroll calc.';

    private int $passed = 0;

    private int $failed = 0;

    private array $failures = [];

    public function handle(CompensationRateResolverService $resolver): int
    {
        $this->info('Running compensation calc tests...');
        DB::beginTransaction();

        try {
            // Tests assume HE/HED/HET active. Activate inside the transaction
            // so production state isn't disturbed (rollback restores).
            CompensationType::whereIn('code', ['HE', 'HED', 'HET', 'VEL'])->update(['is_active' => true]);

            $this->testAutoTierBelowThreshold($resolver);
            $this->testAutoTierAboveThreshold($resolver);
            $this->testExplicitHETHonored($resolver);
            $this->testExplicitHEDPlusAutoTier($resolver);
            $this->testExplicitVeladaHonored($resolver);
            $this->testNoOvertimeReturnsEmpty($resolver);
            $this->testHoursCappedAtAuthorizedTotal($resolver);
            $this->testWeekendPaidViaFinNotOvertime($resolver);
            $this->testGenericCenaPaidPerDay($resolver);
            $this->testHolidayPerDayNoCeil($resolver);
            $this->testExplicitOvertimeNotDoublePaidByGeneric($resolver);
            $this->testWeekendSkippedOnHoliday($resolver);
        } finally {
            DB::rollBack();
        }

        $this->newLine();
        $this->info("PASSED: {$this->passed}");
        if ($this->failed > 0) {
            $this->error("FAILED: {$this->failed}");
            foreach ($this->failures as $f) {
                $this->line('  ✗ '.$f);
            }

            return self::FAILURE;
        }
        $this->info('All payroll compensation tests passed.');

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */
    /* Test scenarios */
    /* ------------------------------------------------------------------ */

    private function testAutoTierBelowThreshold(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 5, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect(),
        );

        $heConcept = collect($result['concepts'])->firstWhere('code', 'HE');
        $hedConcept = collect($result['concepts'])->firstWhere('code', 'HED');

        $this->assert('5h overtime → 5h HE, 0h HED', $heConcept && abs($heConcept['hours'] - 5) < 0.01 && ! $hedConcept);
        $this->assert('5h overtime auto-tier source', $heConcept['source'] === 'auto_tier');
    }

    private function testAutoTierAboveThreshold(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 12, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect(),
        );

        $heConcept = collect($result['concepts'])->firstWhere('code', 'HE');
        $hedConcept = collect($result['concepts'])->firstWhere('code', 'HED');

        $this->assert('12h overtime → 9h HE, 3h HED', $heConcept && $hedConcept
            && abs($heConcept['hours'] - 9) < 0.01
            && abs($hedConcept['hours'] - 3) < 0.01);
    }

    private function testExplicitHETHonored(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        // Approve a 4-hr HET auth — must be paid as HET, not HE.
        $hetType = CompensationType::where('code', 'HET')->first();
        if (! $hetType) {
            $this->assert('HET comp_type exists in DB', false, 'cannot test without HET');

            return;
        }
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $hetType->id,
            'date' => now()->toDateString(),
            'hours' => 4,
            'reason' => 'test HET',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 4, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $hetConcept = collect($result['concepts'])->firstWhere('code', 'HET');
        $heConcept = collect($result['concepts'])->firstWhere('code', 'HE');

        $this->assert('Explicit HET auth → HET concept appears', $hetConcept !== null);
        $this->assert('Explicit HET auth → 4h paid at HET rate', $hetConcept && abs($hetConcept['hours'] - 4) < 0.01);
        $this->assert('Explicit HET auth → no auto-tier HE', ! $heConcept);
        $this->assert('Explicit HET source = explicit_authorization', $hetConcept && $hetConcept['source'] === 'explicit_authorization');
    }

    private function testExplicitHEDPlusAutoTier(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $hedType = CompensationType::where('code', 'HED')->first();
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $hedType->id,
            'date' => now()->toDateString(),
            'hours' => 6,
            'reason' => 'test HED explicit',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        // Total OT is 10: 6 explicit HED + 4 falls back to HE auto-tier.
        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 10, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $explicitHED = collect($result['concepts'])->first(fn ($c) => $c['code'] === 'HED' && $c['source'] === 'explicit_authorization');
        $autoHE = collect($result['concepts'])->first(fn ($c) => $c['code'] === 'HE' && $c['source'] === 'auto_tier');

        $this->assert('Explicit HED accounts for 6h', $explicitHED && abs($explicitHED['hours'] - 6) < 0.01);
        $this->assert('Auto-tier HE consumes remaining 4h', $autoHE && abs($autoHE['hours'] - 4) < 0.01);
    }

    private function testExplicitVeladaHonored(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $velType = CompensationType::where('code', 'VEL')->first();
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'compensation_type_id' => $velType->id,
            'date' => now()->toDateString(),
            'hours' => 3,
            'reason' => 'test velada explicit',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 0, 'velada_hours' => 3, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $velConcept = collect($result['concepts'])->firstWhere('code', 'VEL');

        $this->assert('Explicit VEL auth → VEL concept', $velConcept !== null);
        $this->assert('Explicit VEL auth → 3h', $velConcept && abs($velConcept['hours'] - 3) < 0.01);
        $this->assert('Explicit VEL source = explicit_authorization', $velConcept && $velConcept['source'] === 'explicit_authorization');
    }

    private function testNoOvertimeReturnsEmpty(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 0, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect(),
        );

        $this->assert('No hours → no overtime concepts', empty(collect($result['concepts'])->whereIn('code', ['HE', 'HED', 'HET'])->all()));
        $this->assert('No hours → total = 0', $result['total'] === 0.0 || $result['total'] === 0);
    }

    private function testHoursCappedAtAuthorizedTotal(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $hetType = CompensationType::where('code', 'HET')->first();
        // Auth says 10h but actual extra worked is only 4h.
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $hetType->id,
            'date' => now()->toDateString(),
            'hours' => 10,
            'reason' => 'test cap',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 4, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $hetConcept = collect($result['concepts'])->firstWhere('code', 'HET');
        $this->assert('Auth cap: 10h auth + 4h actual → 4h HET only', $hetConcept && abs($hetConcept['hours'] - 4) < 0.01);
    }

    private function testWeekendPaidViaFinNotOvertime(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        // FIN: weekend premium, fixed $200/day, pulled from check-ins.
        $fin = $this->makeCompType('FIN_T', 'Fin de semana', 'special', 'per_day', 'fixed', 200.0, CompensationType::PULL_RULE_WEEKEND);
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'date' => now()->toDateString(),
            'hours' => 1,
            'reason' => 'test FIN',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 0, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 8],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $finConcept = collect($result['concepts'])->firstWhere('code', 'FIN_T');
        $heConcept = collect($result['concepts'])->firstWhere('code', 'HE');

        $this->assert('FIN weekend → paid at FIN rate ($200/day)', $finConcept && abs($finConcept['amount'] - 200) < 0.01);
        $this->assert('FIN weekend → per-day (days=1)', $finConcept && abs($finConcept['days'] - 1) < 0.01);
        $this->assert('FIN weekend → no overtime/HE concept', ! $heConcept);
        $this->assert('FIN weekend → source = authorization', $finConcept && $finConcept['source'] === 'authorization');
    }

    private function testGenericCenaPaidPerDay(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        // CENA: dinner allowance, fixed $75/day, pulled from check-ins.
        $cena = $this->makeCompType('CENA_T', 'Cena', 'special', 'per_day', 'fixed', 75.0, CompensationType::PULL_RULE_MEAL);
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $cena->id,
            'date' => now()->toDateString(),
            'hours' => 1,
            'reason' => 'test CENA',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 0, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $cenaConcept = collect($result['concepts'])->firstWhere('code', 'CENA_T');

        $this->assert('CENA approved → paid once', $cenaConcept && abs($cenaConcept['amount'] - 75) < 0.01);
        $this->assert('CENA → total = 75', abs($result['total'] - 75) < 0.01);
    }

    private function testHolidayPerDayNoCeil(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        // FEST: holiday worked, 100% per day. 9 worked hours must NOT become
        // ceil(9/8)=2 days; one approved auth row = one day.
        $fest = CompensationType::where('code', 'FEST')->first();
        if (! $fest) {
            $this->assert('FEST comp_type exists in DB', false, 'cannot test without FEST');

            return;
        }
        $fest->update(['is_active' => true]);
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_HOLIDAY_WORKED,
            'compensation_type_id' => $fest->id,
            'date' => now()->toDateString(),
            'hours' => 1,
            'reason' => 'test FEST',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 0, 'velada_hours' => 0, 'holiday_hours' => 9, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $festConcept = collect($result['concepts'])->firstWhere('code', 'FEST');

        $this->assert('Holiday → 1 day (no ceil(9/8)=2)', $festConcept && abs($festConcept['days'] - 1) < 0.01);
        $this->assert('Holiday 100% per day → 2× daily salary (1600)', $festConcept && abs($festConcept['amount'] - 1600) < 0.01);
    }

    private function testExplicitOvertimeNotDoublePaidByGeneric(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        // An explicit HET overtime auth must be paid once by the overtime path
        // and NOT a second time by the generic pass.
        $hetType = CompensationType::where('code', 'HET')->first();
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $hetType->id,
            'date' => now()->toDateString(),
            'hours' => 4,
            'reason' => 'test dedup',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 4, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
        );

        $hetConcepts = collect($result['concepts'])->where('code', 'HET');

        $this->assert('Explicit HET paid exactly once (no generic double)', $hetConcepts->count() === 1);
    }

    private function testWeekendSkippedOnHoliday(CompensationRateResolverService $resolver): void
    {
        $emp = $this->makeEmployee();

        $fin = $this->makeCompType('FIN_H', 'Fin de semana', 'special', 'per_day', 'fixed', 200.0, CompensationType::PULL_RULE_WEEKEND);
        $date = now()->toDateString();
        $auth = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'date' => $date,
            'hours' => 1,
            'reason' => 'test FIN on holiday',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        // The same day is an official holiday → no weekend premium on top.
        $result = $resolver->calculateAllCompensation(
            $emp,
            ['overtime_hours' => 0, 'velada_hours' => 0, 'holiday_hours' => 0, 'weekend_hours' => 0],
            100.0,
            800.0,
            collect([$auth->load('compensationType')]),
            [$date],
        );

        $finConcept = collect($result['concepts'])->firstWhere('code', 'FIN_H');

        $this->assert('Weekend FIN skipped on a holiday date', $finConcept === null);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private static int $counter = 0;

    private function makeCompType(
        string $code,
        string $name,
        string $authType,
        string $applicationMode,
        string $calculationType,
        float $amount,
        ?string $pullRule = null,
    ): CompensationType {
        return CompensationType::create([
            'name' => $name,
            'code' => $code,
            'calculation_type' => $calculationType,
            'percentage_value' => $calculationType === 'percentage' ? $amount : null,
            'fixed_amount' => $calculationType === 'fixed' ? $amount : null,
            'is_active' => true,
            'application_mode' => $applicationMode,
            'authorization_type' => $authType,
            'attendance_pull_rule' => $pullRule,
            'priority' => 0,
        ]);
    }

    private function makeEmployee(): Employee
    {
        self::$counter++;
        $u = self::$counter;
        $pid = getmypid() % 100;

        $dept = Department::factory()->create(['code' => 'PAYTEST_'.$pid.'_'.$u, 'is_active' => true]);

        return Employee::factory()->for($dept)->create([
            'status' => 'active',
            'employee_number' => 'PAY-'.$pid.'-'.$u,
            'zkteco_user_id' => 800000 + ($pid * 1000) + $u,
            'email' => 'pay-'.$pid.'-'.$u.'@example.com',
            'hourly_rate' => 100.00,
        ]);
    }

    private function assert(string $name, bool $condition, string $extra = ''): void
    {
        if ($condition) {
            $this->passed++;
            $this->line("  ✓ {$name}");
        } else {
            $this->failed++;
            $msg = $extra ? "{$name} ({$extra})" : $name;
            $this->failures[] = $msg;
            $this->error("  ✗ {$msg}");
        }
    }
}
