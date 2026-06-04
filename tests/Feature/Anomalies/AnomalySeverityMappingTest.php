<?php

namespace Tests\Feature\Anomalies;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AnomalyDetectorService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * The re-calibrated severity map: incomplete punches (broken payroll data)
 * are CRITICAL; unpaid extra time is a WARNING; the rest is informational.
 * Single source of truth: AttendanceAnomaly::defaultSeverityFor().
 */
class AnomalySeverityMappingTest extends FeatureTestCase
{
    /** Monday, so the default factory schedule (mon-fri 08:00-17:00) applies. */
    private const WORK_DATE = '2026-06-01';

    private function makeRecord(array $attrs = []): AttendanceRecord
    {
        $employee = Employee::factory()->create();

        return AttendanceRecord::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'work_date' => self::WORK_DATE,
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
        ], $attrs));
    }

    // ---------------------------------------------------------------------
    // defaultSeverityFor (unit)
    // ---------------------------------------------------------------------

    public function test_default_severity_map_covers_every_branch(): void
    {
        $critical = AttendanceAnomaly::SEVERITY_CRITICAL;
        $warning = AttendanceAnomaly::SEVERITY_WARNING;
        $info = AttendanceAnomaly::SEVERITY_INFO;

        $this->assertSame($critical, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_MISSING_CHECKOUT));
        $this->assertSame($critical, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_MISSING_CHECKIN));

        $this->assertSame($warning, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_UNAUTHORIZED_VELADA));
        $this->assertSame($warning, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME));
        $this->assertSame($warning, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_VELADA_MISSING_CONFIRMATION));

        $this->assertSame($warning, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_LATE_ARRIVAL, 75));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_LATE_ARRIVAL, 45));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_LATE_ARRIVAL, null));
        $this->assertSame($warning, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_EARLY_DEPARTURE, 90));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_EARLY_DEPARTURE, 30));

        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_EXCESSIVE_BREAK));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_MISSING_LUNCH));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_SCHEDULE_DEVIATION));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_DUPLICATE_PUNCHES));
        $this->assertSame($info, AttendanceAnomaly::defaultSeverityFor(AttendanceAnomaly::TYPE_EXCESSIVE_OVERTIME));
    }

    // ---------------------------------------------------------------------
    // Detector integration
    // ---------------------------------------------------------------------

    public function test_missing_checkout_is_detected_as_critical(): void
    {
        $record = $this->makeRecord([
            'check_out' => null,
            'lunch_out' => null,
            'lunch_in' => null,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_MISSING_CHECKOUT,
            'severity' => AttendanceAnomaly::SEVERITY_CRITICAL,
        ]);
    }

    public function test_missing_checkin_is_detected_as_critical(): void
    {
        $record = $this->makeRecord([
            'check_in' => null,
            'lunch_out' => null,
            'lunch_in' => null,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_MISSING_CHECKIN,
            'severity' => AttendanceAnomaly::SEVERITY_CRITICAL,
        ]);
    }

    public function test_late_arrival_over_60_minutes_is_warning(): void
    {
        $record = $this->makeRecord(['late_minutes' => 75]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_LATE_ARRIVAL,
            'severity' => AttendanceAnomaly::SEVERITY_WARNING,
        ]);
    }

    public function test_late_arrival_under_60_minutes_is_info(): void
    {
        $record = $this->makeRecord(['late_minutes' => 45]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_LATE_ARRIVAL,
            'severity' => AttendanceAnomaly::SEVERITY_INFO,
        ]);
    }

    public function test_early_departure_over_60_minutes_is_warning(): void
    {
        $record = $this->makeRecord(['early_departure_minutes' => 90]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_EARLY_DEPARTURE,
            'severity' => AttendanceAnomaly::SEVERITY_WARNING,
        ]);
    }

    // ---------------------------------------------------------------------
    // Historical remap migration
    // ---------------------------------------------------------------------

    public function test_remap_migration_reclassifies_existing_rows_and_is_reversible(): void
    {
        $record = $this->makeRecord();

        $veladaId = DB::table('attendance_anomalies')->insertGetId([
            'attendance_record_id' => $record->id,
            'employee_id' => $record->employee_id,
            'work_date' => self::WORK_DATE,
            'anomaly_type' => 'unauthorized_velada',
            'severity' => 'critical', // old mapping
            'description' => 'x',
            'status' => 'resolved', // closed rows are remapped too
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checkoutId = DB::table('attendance_anomalies')->insertGetId([
            'attendance_record_id' => $record->id,
            'employee_id' => $record->employee_id,
            'work_date' => self::WORK_DATE,
            'anomaly_type' => 'missing_checkout',
            'severity' => 'warning', // old mapping
            'description' => 'x',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_06_05_000002_remap_attendance_anomaly_severities.php');

        $migration->up();
        $this->assertSame('warning', DB::table('attendance_anomalies')->find($veladaId)->severity);
        $this->assertSame('critical', DB::table('attendance_anomalies')->find($checkoutId)->severity);

        $migration->down();
        $this->assertSame('critical', DB::table('attendance_anomalies')->find($veladaId)->severity);
        $this->assertSame('warning', DB::table('attendance_anomalies')->find($checkoutId)->severity);
    }
}
