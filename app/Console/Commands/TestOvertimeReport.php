<?php

namespace App\Console\Commands;

use App\Exports\OvertimeWeekly\OvertimeWeeklyExport;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Reports\OvertimeReportTemplateRegistry;
use App\Services\Reports\Templates\BiesTemplate;
use App\Services\Reports\Templates\CalidadTemplate;
use App\Services\Reports\Templates\CorteTemplate;
use App\Services\Reports\Templates\DefaultTemplate;
use App\Services\Reports\Templates\DisenoTemplate;
use App\Services\Reports\WeeklyOvertimeReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Extensive end-to-end verification of the weekly overtime report.
 *
 * Runs every scenario inside a single DB transaction that rolls back —
 * no test data persists. Useful for manual confidence checks; not a unit test.
 */
class TestOvertimeReport extends Command
{
    protected $signature = 'reports:test-overtime-weekly {--keep : Do not roll back; keep test data in DB}';

    protected $description = 'Run extensive scenarios against WeeklyOvertimeReportService and verify outputs.';

    private int $passed = 0;

    private int $failed = 0;

    private array $failures = [];

    /** @var array<string,int> */
    private array $compIds = [];

    public function handle(
        WeeklyOvertimeReportService $reportService,
        OvertimeReportTemplateRegistry $registry,
    ): int {
        $this->info('Iniciando suite de verificación...');
        DB::beginTransaction();

        try {
            $this->setupCompTypeIds();

            $this->testRegistryResolution($registry);
            $this->testEmptyDepartment($reportService);
            $this->testWeekNormalization($reportService);
            $this->testInactiveAndDeletedExcluded($reportService);

            // The big one: scenarios with auths + checadas
            $this->testHourBuckets($reportService);
            $this->testMarkers($reportService);
            $this->testAuthStatusFiltering($reportService);
            $this->testNoCheckadaZeroes($reportService);
            $this->testWeekBoundaries($reportService);
            $this->testCrossDepartmentIsolation($reportService);
            $this->testGrandTotals($reportService);
            $this->testMVSplit($reportService);
            $this->testObservations($reportService);
            $this->testAuthWithoutCompType($reportService);
            $this->testMultipleAuthsSameDay($reportService);

            $this->testPdfRendersForAllTemplates($reportService, $registry);
            $this->testExcelExportsForAllTemplates($reportService, $registry);
            $this->testTransposedBiesShape($reportService);
            $this->testDisenoMVColumnCount($reportService);
        } finally {
            if ($this->option('keep')) {
                DB::commit();
                $this->warn('--keep flag: cambios commit-eados.');
            } else {
                DB::rollBack();
            }
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
        $this->info('All tests passed.');

        return self::SUCCESS;
    }

    /* -------------------------------------------------------------------- */
    /* Test scenarios */
    /* -------------------------------------------------------------------- */

    private function testRegistryResolution(OvertimeReportTemplateRegistry $registry): void
    {
        $cases = [
            'BIES' => BiesTemplate::class,
            'CALIDAD' => CalidadTemplate::class,
            'CORTE' => CorteTemplate::class,
            'DISENO' => DisenoTemplate::class,
            'TELAS' => DefaultTemplate::class, // fallback for unmapped codes
            'NUEVO' => DefaultTemplate::class,
            '' => DefaultTemplate::class,
        ];

        // The registry only inspects $department->code; no need to persist (avoids unique-code collisions).
        foreach ($cases as $code => $expected) {
            $dept = Department::factory()->make(['code' => $code]);
            $tpl = $registry->for($dept);
            $this->assert(
                "registry resolves [{$code}] to ".class_basename($expected),
                get_class($tpl) === $expected,
                'got '.class_basename($tpl),
            );
        }
    }

    private function testEmptyDepartment(WeeklyOvertimeReportService $svc): void
    {
        $dept = Department::factory()->create(['code' => 'EMPTY', 'is_active' => true]);
        $report = $svc->buildReport($dept, Carbon::parse('2026-03-04'));

        $this->assert('empty dept: no rows', count($report['rows']) === 0);
        $this->assert('empty dept: total=0', $report['totals']['total_hours'] === 0.0);
        $this->assert('empty dept: 7 dates', count($report['dates']) === 7);
        $this->assert('empty dept: week_start=Monday', Carbon::parse($report['week_start'])->dayOfWeekIso === 1);
        $this->assert('empty dept: week_end=Sunday', Carbon::parse($report['week_end'])->dayOfWeekIso === 7);
    }

    private function testWeekNormalization(WeeklyOvertimeReportService $svc): void
    {
        $dept = Department::factory()->create(['code' => 'WEEK_NORM', 'is_active' => true]);

        // Wednesday, Friday, Sunday all should resolve to same Monday (2026-03-02)
        foreach (['2026-03-04', '2026-03-06', '2026-03-08'] as $anyDate) {
            $r = $svc->buildReport($dept, Carbon::parse($anyDate));
            $this->assert(
                "week normalization {$anyDate} → 2026-03-02",
                $r['week_start'] === '2026-03-02' && $r['week_end'] === '2026-03-08',
            );
        }
    }

    private function testInactiveAndDeletedExcluded(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('STATUS_TEST');

        $active = $this->makeEmployee($dept);
        $inactive = $this->makeEmployee($dept);
        $inactive->update(['status' => 'inactive']);
        $terminated = $this->makeEmployee($dept);
        $terminated->update(['status' => 'terminated']);
        $soft = $this->makeEmployee($dept);
        $soft->delete();

        $r = $svc->buildReport($dept, Carbon::parse('2026-03-04'));
        $names = collect($r['rows'])->pluck('employee.id')->all();

        $this->assert('active employee included', in_array($active->id, $names, true));
        $this->assert('inactive employee excluded', ! in_array($inactive->id, $names, true));
        $this->assert('terminated employee excluded', ! in_array($terminated->id, $names, true));
        $this->assert('soft-deleted employee excluded', ! in_array($soft->id, $names, true));
    }

    private function testHourBuckets(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('BUCKETS');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04'); // wednesday

        $this->makeRecord($emp, $date);
        $this->makeAuth($emp, $date, 'HE', 2.0);
        $this->makeAuth($emp, $date, 'HED', 1.5);
        $this->makeAuth($emp, $date, 'HET', 0.5);
        $this->makeAuth($emp, $date, 'FIN', 3.0);

        $r = $svc->buildReport($dept, $date);
        $row = $r['rows'][0];

        $this->assertNum('HE+HED+HET → total 4.0', $row['totals']['total_hours'], 4.0);
        $this->assertNum('FIN → weekend 3.0', $row['totals']['weekend_hours'], 3.0);
        $this->assertNum('day cell = 4.0', $row['days'][$date->toDateString()]['overtime_hours'], 4.0);
        $this->assertNum(
            'weekend hours stored at day-level',
            $row['days'][$date->toDateString()]['weekend_hours'],
            3.0,
        );
    }

    private function testMarkers(WeeklyOvertimeReportService $svc): void
    {
        // VEL alone
        $dept = $this->makeDept('MARK_VEL');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        $this->makeRecord($emp, $date);
        $this->makeAuth($emp, $date, 'VEL', 1);
        $r = $svc->buildReport($dept, $date);
        $this->assert('VEL alone: vel=1', $r['rows'][0]['totals']['velada_count'] === 1);
        $this->assert('VEL alone: cena=0', $r['rows'][0]['totals']['cena_count'] === 0);
        $this->assert('VEL alone: com=0', $r['rows'][0]['totals']['comida_count'] === 0);
        $this->assertNum('VEL not in TOTAL', $r['rows'][0]['totals']['total_hours'], 0.0);

        // Cena alone
        $dept2 = $this->makeDept('MARK_CENA');
        $emp2 = $this->makeEmployee($dept2);
        $this->makeRecord($emp2, $date);
        $this->makeAuth($emp2, $date, 'CENA', 1);
        $r2 = $svc->buildReport($dept2, $date);
        $this->assert('Cena alone: cena=1', $r2['rows'][0]['totals']['cena_count'] === 1);
        $this->assert('Cena alone: vel=0', $r2['rows'][0]['totals']['velada_count'] === 0);

        // COM alone
        $dept3 = $this->makeDept('MARK_COM');
        $emp3 = $this->makeEmployee($dept3);
        $this->makeRecord($emp3, $date);
        $this->makeAuth($emp3, $date, 'COM', 1);
        $r3 = $svc->buildReport($dept3, $date);
        $this->assert('COM alone: com=1', $r3['rows'][0]['totals']['comida_count'] === 1);
        $this->assert('COM alone: vel=0', $r3['rows'][0]['totals']['velada_count'] === 0);
        $this->assert('COM alone: cena=0', $r3['rows'][0]['totals']['cena_count'] === 0);
    }

    private function testAuthStatusFiltering(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('STATUS_FILTER');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        $this->makeRecord($emp, $date);

        $this->makeAuth($emp, $date, 'HE', 1, 'pending');
        $this->makeAuth($emp, $date, 'HE', 2, 'rejected');
        $this->makeAuth($emp, $date, 'HE', 3, 'approved');
        $this->makeAuth($emp, $date, 'HE', 4, 'paid');

        $r = $svc->buildReport($dept, $date);
        $this->assertNum('only approved+paid count: 3+4=7', $r['rows'][0]['totals']['total_hours'], 7.0);
    }

    private function testNoCheckadaZeroes(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('NO_CHECKADA');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');

        // Auth exists but no AttendanceRecord at all
        $this->makeAuth($emp, $date, 'HE', 5);
        $r = $svc->buildReport($dept, $date);
        $this->assertNum('no record → total=0', $r['rows'][0]['totals']['total_hours'], 0.0);

        // Now add a record with check_in but no check_out
        AttendanceRecord::create([
            'employee_id' => $emp->id,
            'work_date' => $date->toDateString(),
            'check_in' => '08:00:00',
            'check_out' => null,
            'worked_hours' => 0,
            'status' => 'partial',
        ]);
        $r2 = $svc->buildReport($dept, $date);
        $this->assertNum('record without check_out → total=0', $r2['rows'][0]['totals']['total_hours'], 0.0);
    }

    private function testWeekBoundaries(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('WEEK_BOUNDS');
        $emp = $this->makeEmployee($dept);
        $weekDate = Carbon::parse('2026-03-04'); // wednesday

        $this->makeRecord($emp, Carbon::parse('2026-02-25')); // previous week
        $this->makeRecord($emp, Carbon::parse('2026-03-04')); // in week
        $this->makeRecord($emp, Carbon::parse('2026-03-11')); // next week
        $this->makeAuth($emp, Carbon::parse('2026-02-25'), 'HE', 99);
        $this->makeAuth($emp, Carbon::parse('2026-03-04'), 'HE', 5);
        $this->makeAuth($emp, Carbon::parse('2026-03-11'), 'HE', 99);

        $r = $svc->buildReport($dept, $weekDate);
        $this->assertNum('week boundary: only mid-week counted', $r['rows'][0]['totals']['total_hours'], 5.0);
    }

    private function testCrossDepartmentIsolation(WeeklyOvertimeReportService $svc): void
    {
        $deptA = $this->makeDept('ISOL_A');
        $deptB = $this->makeDept('ISOL_B');
        $empA = $this->makeEmployee($deptA);
        $empB = $this->makeEmployee($deptB);
        $date = Carbon::parse('2026-03-04');

        $this->makeRecord($empA, $date);
        $this->makeRecord($empB, $date);
        $this->makeAuth($empA, $date, 'HE', 5);
        $this->makeAuth($empB, $date, 'HE', 99);

        $rA = $svc->buildReport($deptA, $date);
        $this->assert('isolation: deptA has 1 row', count($rA['rows']) === 1);
        $this->assertNum('isolation: deptA total=5', $rA['totals']['total_hours'], 5.0);
    }

    private function testGrandTotals(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('GRAND_TOTALS');
        $e1 = $this->makeEmployee($dept);
        $e2 = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');

        $this->makeRecord($e1, $date);
        $this->makeRecord($e2, $date);
        $this->makeAuth($e1, $date, 'HE', 3);
        $this->makeAuth($e2, $date, 'HE', 4);
        $this->makeAuth($e1, $date, 'FIN', 2);
        $this->makeAuth($e1, $date, 'VEL', 1);
        $this->makeAuth($e2, $date, 'CENA', 1);
        $this->makeAuth($e2, $date, 'COM', 1);

        $r = $svc->buildReport($dept, $date);
        $this->assertNum('grand total_hours = 7', $r['totals']['total_hours'], 7.0);
        $this->assertNum('grand weekend = 2', $r['totals']['weekend_hours'], 2.0);
        $this->assert('grand vel = 1', $r['totals']['velada_count'] === 1);
        $this->assert('grand cena = 1', $r['totals']['cena_count'] === 1);
        $this->assert('grand com = 1', $r['totals']['comida_count'] === 1);
        $this->assert('grand employee_count = 2', $r['totals']['employee_count'] === 2);
    }

    private function testMVSplit(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('MV_SPLIT');
        $empDay = $this->makeEmployee($dept);
        $empNight = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');

        $this->makeRecord($empDay, $date, isNightShift: false);
        $this->makeRecord($empNight, $date, isNightShift: true);
        $this->makeAuth($empDay, $date, 'HE', 3);
        $this->makeAuth($empNight, $date, 'HE', 2);

        $r = $svc->buildReport($dept, $date);
        foreach ($r['rows'] as $row) {
            $day = $row['days'][$date->toDateString()];
            if ($row['employee']['id'] === $empDay->id) {
                $this->assertNum('day shift → m=3', $day['m_hours'], 3.0);
                $this->assertNum('day shift → v=0', $day['v_hours'], 0.0);
            } else {
                $this->assertNum('night shift → m=0', $day['m_hours'], 0.0);
                $this->assertNum('night shift → v=2', $day['v_hours'], 2.0);
            }
        }
    }

    private function testObservations(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('OBS_TEST');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        $rec = $this->makeRecord($emp, $date);
        $rec->update(['notes' => 'Llegó tarde por tráfico']);
        $this->makeAuth($emp, $date, 'HE', 2, 'approved', 'Inventario urgente');

        $r = $svc->buildReport($dept, $date);
        $obs = $r['rows'][0]['observations'];
        $this->assert('obs contains note', str_contains($obs, 'Llegó tarde por tráfico'));
        $this->assert('obs contains auth reason', str_contains($obs, 'Inventario urgente'));
    }

    private function testAuthWithoutCompType(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('NO_COMPTYPE');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        $this->makeRecord($emp, $date);

        Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => 'overtime',
            'compensation_type_id' => null, // no comp_type
            'date' => $date->toDateString(),
            'hours' => 5,
            'reason' => 'no comp_type',
            'status' => 'approved',
        ]);

        $r = $svc->buildReport($dept, $date);
        $this->assertNum('auth without comp_type → ignored', $r['rows'][0]['totals']['total_hours'], 0.0);
    }

    private function testMultipleAuthsSameDay(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('MULTI_AUTH');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        $this->makeRecord($emp, $date);

        // 3 separate HE auths same day
        $this->makeAuth($emp, $date, 'HE', 1);
        $this->makeAuth($emp, $date, 'HE', 2);
        $this->makeAuth($emp, $date, 'HE', 0.5);

        $r = $svc->buildReport($dept, $date);
        $this->assertNum('multiple HE same day sum: 3.5', $r['rows'][0]['totals']['total_hours'], 3.5);
    }

    private function testPdfRendersForAllTemplates(
        WeeklyOvertimeReportService $svc,
        OvertimeReportTemplateRegistry $registry,
    ): void {
        foreach (['BIES', 'CALIDAD', 'CORTE', 'DISENO', 'TELAS'] as $code) {
            $dept = $this->makeDept("PDF_{$code}_".uniqid());
            // resolve using the canonical code (independent from the persisted dept)
            $deptForTpl = Department::factory()->make(['code' => $code]);
            $tpl = $registry->for($deptForTpl);

            $emp = $this->makeEmployee($dept);
            $this->makeRecord($emp, Carbon::parse('2026-03-04'));
            $this->makeAuth($emp, Carbon::parse('2026-03-04'), 'HE', 2);

            $report = $svc->buildReport($dept, Carbon::parse('2026-03-04'));
            $pdf = Pdf::loadView($tpl->pdfView(), ['report' => $report])->setPaper('a4', 'landscape');

            $bytes = strlen($pdf->output());
            $this->assert("PDF renders for {$code} ({$tpl->vueComponent()})", $bytes > 5000, "got {$bytes} bytes");
        }
    }

    private function testExcelExportsForAllTemplates(
        WeeklyOvertimeReportService $svc,
        OvertimeReportTemplateRegistry $registry,
    ): void {
        foreach (['BIES', 'CALIDAD', 'CORTE', 'DISENO', 'TELAS'] as $code) {
            $dept = $this->makeDept("XLS_{$code}_".uniqid());
            $deptForTpl = Department::factory()->make(['code' => $code]);
            $tpl = $registry->for($deptForTpl);

            $emp = $this->makeEmployee($dept);
            $this->makeRecord($emp, Carbon::parse('2026-03-04'));
            $this->makeAuth($emp, Carbon::parse('2026-03-04'), 'HE', 2);

            $report = $svc->buildReport($dept, Carbon::parse('2026-03-04'));
            $export = new OvertimeWeeklyExport(
                $tpl->excelHeadings($report),
                $tpl->excelRows($report),
                'Test',
            );
            $bytes = Excel::raw($export, ExcelType::XLSX);
            $this->assert("Excel renders for {$code}", strlen($bytes) > 1000);
        }
    }

    private function testTransposedBiesShape(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('BIES_SHAPE');
        $e1 = $this->makeEmployee($dept);
        $e2 = $this->makeEmployee($dept);
        $e3 = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        foreach ([$e1, $e2, $e3] as $e) {
            $this->makeRecord($e, $date);
            $this->makeAuth($e, $date, 'HE', 1);
        }

        $report = $svc->buildReport($dept, $date);
        $bies = new BiesTemplate;
        $headings = $bies->excelHeadings($report);
        // Expected: CONCEPTO + 3 employees + TOTAL = 5 columns
        $this->assert('BIES headings = 1 + N + 1', count($headings) === 5, 'got '.count($headings));
        $this->assert('BIES first heading = CONCEPTO', $headings[0] === 'CONCEPTO');

        $rows = $bies->excelRows($report);
        $this->assert(
            'BIES rows = 7 days + 6 totals (TOTAL/CENA/VELADA/CENA-with-date/FIN/COMIDA)',
            count($rows) === 13,
            'got '.count($rows),
        );
    }

    private function testDisenoMVColumnCount(WeeklyOvertimeReportService $svc): void
    {
        $dept = $this->makeDept('DISENO_SHAPE');
        $emp = $this->makeEmployee($dept);
        $date = Carbon::parse('2026-03-04');
        $this->makeRecord($emp, $date);
        $this->makeAuth($emp, $date, 'HE', 1);

        $report = $svc->buildReport($dept, $date);
        $diseno = new DisenoTemplate;
        $headings = $diseno->excelHeadings($report);
        // NOMBRE + 7 days × 2 (M/V) + TOTAL HORAS + FIN DE SEMANA + COMIDA + VELADA + CENA + OBSERVACIONES = 1+14+5+1 = 21
        $this->assert(
            'DISENO headings = 1 + 14 + 6 = 21',
            count($headings) === 21,
            'got '.count($headings),
        );
    }

    /* -------------------------------------------------------------------- */
    /* Setup helpers */
    /* -------------------------------------------------------------------- */

    private function setupCompTypeIds(): void
    {
        foreach (['HE', 'HED', 'HET', 'VEL', 'FIN', 'Cena', 'COM'] as $code) {
            $ct = CompensationType::where('code', $code)->first();
            if (! $ct) {
                throw new \RuntimeException("CompensationType code '{$code}' not found in DB. Run migrations + seeder.");
            }
            $this->compIds[strtoupper($code)] = $ct->id;
        }
    }

    private function makeDept(string $code): Department
    {
        // Suffix avoids collisions with real departments and across re-runs.
        return Department::factory()->create([
            'code' => $code.'_'.substr(uniqid(), -6),
            'is_active' => true,
        ]);
    }

    private static int $employeeCounter = 0;

    private function makeEmployee(Department $dept): Employee
    {
        // Append a unique counter+pid so test employees never collide with real seeded data.
        self::$employeeCounter++;
        $u = self::$employeeCounter;
        $pid = getmypid() % 100;

        return Employee::factory()->for($dept)->create([
            'status' => 'active',
            'employee_number' => 'TEST-'.$pid.'-'.$u,
            'zkteco_user_id' => 900000 + ($pid * 1000) + $u,
            'email' => 'test-'.$pid.'-'.$u.'@example.com',
        ]);
    }

    private function makeRecord(
        Employee $emp,
        Carbon $date,
        bool $isNightShift = false,
        bool $isWeekendWork = false,
    ): AttendanceRecord {
        return AttendanceRecord::create([
            'employee_id' => $emp->id,
            'work_date' => $date->toDateString(),
            'check_in' => '08:00:00',
            'check_out' => '18:00:00',
            'worked_hours' => 9,
            'overtime_hours' => 1,
            'is_night_shift' => $isNightShift,
            'is_weekend_work' => $isWeekendWork,
            'status' => 'present',
        ]);
    }

    private function makeAuth(
        Employee $emp,
        Carbon $date,
        string $compCode,
        float $hours,
        string $status = 'approved',
        string $reason = 'test',
    ): Authorization {
        $key = strtoupper($compCode);
        if (! isset($this->compIds[$key])) {
            throw new \RuntimeException("Unknown comp code: {$compCode}");
        }

        $authType = match ($key) {
            'HE', 'HED', 'HET' => 'overtime',
            'VEL' => 'night_shift',
            default => 'special',
        };

        return Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => 1,
            'type' => $authType,
            'compensation_type_id' => $this->compIds[$key],
            'date' => $date->toDateString(),
            'hours' => $hours,
            'reason' => $reason,
            'status' => $status,
        ]);
    }

    /* -------------------------------------------------------------------- */
    /* Assertions */
    /* -------------------------------------------------------------------- */

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

    private function assertNum(string $name, $actual, float $expected, float $epsilon = 0.001): void
    {
        $diff = abs((float) $actual - $expected);
        $this->assert($name, $diff < $epsilon, "expected {$expected}, got {$actual}");
    }
}
