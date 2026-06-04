<?php

namespace Tests\Feature\Anomalies;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\AnomalyDetectorService;
use Tests\FeatureTestCase;

/**
 * Generalized self-heal: open auto-detected anomalies whose structural
 * condition no longer holds on the record are auto-resolved as
 * record_corrected. Anomalies owned by the linking flow (covered by an
 * authorization or incident while the raw condition persists) are NOT
 * touched.
 */
class AnomalySelfHealTest extends FeatureTestCase
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

    private function makeOpenAnomaly(AttendanceRecord $record, string $type, array $attrs = []): AttendanceAnomaly
    {
        return AttendanceAnomaly::factory()->open()->create(array_merge([
            'employee_id' => $record->employee_id,
            'attendance_record_id' => $record->id,
            'work_date' => $record->work_date,
            'anomaly_type' => $type,
        ], $attrs));
    }

    public function test_missing_checkout_auto_resolves_when_checkout_added(): void
    {
        // Anomaly created while check_out was missing; the record now has one.
        $record = $this->makeRecord([
            'has_anomalies' => true,
            'anomaly_count' => 1,
        ]);
        $anomaly = $this->makeOpenAnomaly($record, AttendanceAnomaly::TYPE_MISSING_CHECKOUT);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $anomaly->refresh();
        $this->assertSame(AttendanceAnomaly::STATUS_RESOLVED, $anomaly->status);
        $this->assertSame(AttendanceAnomaly::METHOD_RECORD_CORRECTED, $anomaly->resolution_method);

        $record->refresh();
        $this->assertFalse((bool) $record->has_anomalies);
        $this->assertSame(0, (int) $record->anomaly_count);
    }

    public function test_early_departure_with_psa_keeps_open_when_minutes_still_exceed_threshold(): void
    {
        // A PSA permiso suppresses re-detection, but the structural condition
        // (early_departure_minutes > 15) still holds — the linking flow owns
        // this anomaly, generic self-heal must NOT close it.
        $record = $this->makeRecord([
            'early_departure_minutes' => 90,
            'has_anomalies' => true,
            'anomaly_count' => 1,
        ]);
        $anomaly = $this->makeOpenAnomaly($record, AttendanceAnomaly::TYPE_EARLY_DEPARTURE, [
            'deviation_minutes' => 90,
        ]);

        // PSA is seeded by migration — reuse it instead of recreating.
        $type = IncidentType::firstWhere('code', 'PSA')
            ?? IncidentType::factory()->permission()->create(['code' => 'PSA']);
        Incident::factory()->approved()->create([
            'employee_id' => $record->employee_id,
            'incident_type_id' => $type->id,
            'start_date' => self::WORK_DATE,
            'end_date' => self::WORK_DATE,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $anomaly->refresh();
        $this->assertSame(AttendanceAnomaly::STATUS_OPEN, $anomaly->status);
    }

    public function test_manually_created_anomaly_is_not_auto_closed(): void
    {
        $record = $this->makeRecord([
            'has_anomalies' => true,
            'anomaly_count' => 1,
        ]);
        $anomaly = $this->makeOpenAnomaly($record, AttendanceAnomaly::TYPE_MISSING_CHECKOUT, [
            'auto_detected' => false,
        ]);

        app(AnomalyDetectorService::class)->detectForRecord($record);

        $anomaly->refresh();
        $this->assertSame(AttendanceAnomaly::STATUS_OPEN, $anomaly->status);
    }

    public function test_attendance_manual_edit_triggers_self_heal(): void
    {
        // End-to-end: editing the record via the controller (adding the
        // missing check_out) recalculates + re-detects, closing the anomaly.
        $this->actingAsAdmin();

        $record = $this->makeRecord([
            'check_out' => null,
            'lunch_out' => null,
            'lunch_in' => null,
            'has_anomalies' => true,
            'anomaly_count' => 1,
        ]);
        $anomaly = $this->makeOpenAnomaly($record, AttendanceAnomaly::TYPE_MISSING_CHECKOUT);

        $this->put(route('attendance.update', $record), [
            'check_in' => '08:00',
            'check_out' => '17:00',
            'status' => 'present',
            'manual_edit_reason' => 'Empleado olvido checar salida.',
        ])->assertSessionHas('success');

        $anomaly->refresh();
        $this->assertSame(AttendanceAnomaly::STATUS_RESOLVED, $anomaly->status);
        $this->assertSame(AttendanceAnomaly::METHOD_RECORD_CORRECTED, $anomaly->resolution_method);
    }
}
