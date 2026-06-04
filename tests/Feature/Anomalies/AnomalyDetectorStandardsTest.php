<?php

namespace Tests\Feature\Anomalies;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AnomalyDetectorService;
use Tests\FeatureTestCase;

/**
 * The anomaly detector must use the SAME standard as authorizations:
 * the official rounding ladder (<30 min → 0h) applied to schedule-based
 * segments. Time that rounds to 0h can never be authorized (the create
 * form blocks 0-hour authorizations), so it must not generate anomalies.
 */
class AnomalyDetectorStandardsTest extends FeatureTestCase
{
    /** Monday, so the default factory schedule (mon-fri 08:00-17:00) applies. */
    private const WORK_DATE = '2026-06-01';

    /** Build an employee + attendance record pair on a scheduled workday. */
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

    public function test_overtime_under_30_minutes_does_not_create_anomaly(): void
    {
        // 15 min past scheduled exit → rounds to 0h → not authorizable → no anomaly.
        $record = $this->makeRecord([
            'check_out' => '17:15:00',
            'overtime_hours' => 0.25,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseMissing('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME,
        ]);
    }

    public function test_overtime_of_45_minutes_creates_anomaly_with_rounded_hours(): void
    {
        // 45 min past scheduled exit → rounds to 0.5h, same as the authorization
        // "Cargar desde checadas" flow and the weekly report would show.
        $record = $this->makeRecord([
            'check_out' => '17:45:00',
            'overtime_hours' => 0.75,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME,
            'status' => AttendanceAnomaly::STATUS_OPEN,
            'actual_value' => '0.5',
            'deviation_minutes' => 30,
        ]);
    }

    public function test_velada_under_30_minutes_does_not_create_anomaly(): void
    {
        // 24 min of velada → rounds to 0h → not authorizable → no anomaly.
        $record = $this->makeRecord([
            'velada_hours' => 0.4,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $this->assertDatabaseMissing('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_VELADA,
        ]);
    }

    public function test_velada_of_two_hours_without_authorization_creates_anomaly(): void
    {
        $record = $this->makeRecord([
            'velada_hours' => 2,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        // Unpaid extra work is an operational follow-up, not an emergency:
        // warning under the re-calibrated severity map.
        $this->assertDatabaseHas('attendance_anomalies', [
            'attendance_record_id' => $record->id,
            'anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_VELADA,
            'status' => AttendanceAnomaly::STATUS_OPEN,
            'severity' => AttendanceAnomaly::SEVERITY_WARNING,
            'actual_value' => '2',
        ]);
    }

    public function test_open_redundant_anomaly_is_auto_resolved_on_redetection(): void
    {
        // An anomaly created under the old raw rule (15 min of OT) no longer
        // meets the authorization standard — re-detection must self-heal it.
        $record = $this->makeRecord([
            'check_out' => '17:15:00',
            'overtime_hours' => 0.25,
            'has_anomalies' => true,
            'anomaly_count' => 1,
        ]);

        $anomaly = AttendanceAnomaly::factory()->open()->create([
            'employee_id' => $record->employee_id,
            'attendance_record_id' => $record->id,
            'work_date' => $record->work_date,
            'anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME,
            'deviation_minutes' => 15,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $anomaly->refresh();
        $this->assertSame(AttendanceAnomaly::STATUS_RESOLVED, $anomaly->status);
        $this->assertStringContainsString('redondea a 0 horas', (string) $anomaly->resolution_notes);

        $record->refresh();
        $this->assertFalse((bool) $record->has_anomalies);
        $this->assertSame(0, (int) $record->anomaly_count);
    }
}
