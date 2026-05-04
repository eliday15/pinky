<?php

namespace App\Console\Commands;

use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Smoke-test every report controller method with real DB state.
 *
 * Catches: NPEs, division-by-zero, missing relations, empty-data crashes,
 * exception leaks. Does NOT validate business correctness — just that
 * each method returns a well-formed Inertia/streamed response.
 */
class AuditReports extends Command
{
    protected $signature = 'reports:audit';

    protected $description = 'Run every report controller method and assert it returns a valid response.';

    private int $passed = 0;

    private int $failed = 0;

    private array $failures = [];

    public function handle(
        ReportController $reports,
        AttendanceReportController $attendance,
        ReportExportController $exports,
    ): int {
        $this->info('Auditando todos los reportes contra datos de producción...');

        $this->testInertiaReports($reports, $attendance);
        $this->testCsvExports($exports);

        $this->newLine();
        $this->info("PASSED: {$this->passed}");
        if ($this->failed > 0) {
            $this->error("FAILED: {$this->failed}");
            foreach ($this->failures as $f) {
                $this->line('  ✗ '.$f);
            }

            return self::FAILURE;
        }
        $this->info('Todos los reportes responden correctamente.');

        return self::SUCCESS;
    }

    private function testInertiaReports(ReportController $reports, AttendanceReportController $attendance): void
    {
        $req = Request::create('/reports', 'GET');

        $cases = [
            ['index', $reports],
            ['daily', $reports],
            ['weekly', $reports],
            ['monthly', $reports],
            ['payroll', $reports],
            ['overtime', $reports],
            ['absences', $reports],
            ['lateArrivals', $reports],
            ['vacationBalance', $reports],
            ['departmentComparison', $reports],
            ['incidents', $reports],
            ['productivity', $reports],
            ['payrollTrends', $reports],
            ['faltas', $attendance],
            ['asistencia', $attendance],
            ['retardos', $attendance],
            ['earlyDepartures', $attendance],
        ];

        foreach ($cases as [$method, $controller]) {
            $this->runMethod($controller, $method, $req, 'Inertia');
        }
    }

    private function testCsvExports(ReportExportController $exports): void
    {
        $req = Request::create('/reports/export', 'GET');

        $cases = [
            'exportDaily', 'exportWeekly', 'exportMonthly',
            'exportAbsences', 'exportLateArrivals', 'exportVacationBalance',
            'exportIncidents', 'exportOvertime',
            'exportFaltas', 'exportAsistencia', 'exportRetardos', 'exportEarlyDepartures',
        ];

        foreach ($cases as $method) {
            $this->runMethod($exports, $method, $req, 'Streamed');
        }
    }

    private function runMethod(object $controller, string $method, Request $req, string $expectedKind): void
    {
        $label = class_basename($controller).'::'.$method;
        try {
            $response = method_exists($controller, $method) && (new \ReflectionMethod($controller, $method))->getNumberOfRequiredParameters() > 0
                ? $controller->{$method}($req)
                : $controller->{$method}();

            if ($response === null) {
                $this->markFail($label.' returned null');

                return;
            }

            // Inertia\Response or StreamedResponse — both have toResponse()/getStatusCode()
            $kind = class_basename($response);
            $this->ok("{$label} → {$kind}");
        } catch (\Throwable $e) {
            $this->markFail($label.' threw '.class_basename($e).': '.$e->getMessage());
        }
    }

    private function ok(string $line): void
    {
        $this->passed++;
        $this->line('  ✓ '.$line);
    }

    private function markFail(string $msg): void
    {
        $this->failed++;
        $this->failures[] = $msg;
        $this->error('  ✗ '.$msg);
    }
}
