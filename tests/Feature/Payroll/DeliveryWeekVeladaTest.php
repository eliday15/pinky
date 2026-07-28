<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\DeliveryWeek;
use App\Models\Employee;
use App\Models\User;
use App\Services\Reports\WeeklyOvertimeReportService;
use App\Services\VeladaCalculatorService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Personal de entregas por semana (Dani 2026-07-28).
 *
 * Los que salen a entregas se van turnando; cada semana RRHH marca quiénes
 * salieron. A los marcados, su velada y tiempo extra AUTORIZADOS se pagan/
 * reflejan completos esa semana, sin topar contra la checada (que no los
 * alcanza a registrar porque andan en la calle). Como el reporte lee
 * velada_hours y la nómina lee velada_authorized_hours del mismo registro,
 * arreglarlo en VeladaCalculatorService alinea a ambos.
 */
class DeliveryWeekVeladaTest extends FeatureTestCase
{
    private const DATE = '2026-06-03';        // miércoles

    private const WEEK_START = '2026-06-01';  // lunes de esa semana

    private function calculator(): VeladaCalculatorService
    {
        return app(VeladaCalculatorService::class);
    }

    private function approveVelada(Employee $employee, float $hours): void
    {
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => self::DATE,
            'hours' => $hours,
            'reason' => 'velada por entrega',
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    /** Día trabajado que NO alcanza la ventana de velada (22:00–05:00). */
    private function dayRecord(Employee $employee, ?string $checkOut = '18:00:00'): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => $checkOut,
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);
    }

    private function markDelivery(Employee $employee, string $weekStart = self::WEEK_START): void
    {
        DeliveryWeek::create(['employee_id' => $employee->id, 'week_start' => $weekStart]);
    }

    public function test_velada_is_capped_to_zero_without_a_delivery_mark(): void
    {
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);

        $split = $this->calculator()->calculate($record, $e);

        $this->assertEqualsWithDelta(0.0, $split['velada_authorized'], 0.01);
    }

    public function test_delivery_week_pays_full_authorized_velada(): void
    {
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);
        $this->markDelivery($e);

        $split = $this->calculator()->calculate($record, $e);

        $this->assertEqualsWithDelta(3.0, $split['velada_authorized'], 0.01, 'la velada autorizada se paga completa');
        $this->assertEqualsWithDelta(3.0, $split['velada_hours'], 0.01, 'reporte y recibo leen la misma cifra');
    }

    public function test_delivery_week_pays_velada_even_without_checkout(): void
    {
        // Caso real (Pedro/Norma): entrada sin salida → antes velada = 0.
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e, checkOut: null);
        $this->approveVelada($e, 2.5);
        $this->markDelivery($e);

        $split = $this->calculator()->calculate($record, $e);

        $this->assertEqualsWithDelta(2.5, $split['velada_authorized'], 0.01);
    }

    public function test_a_mark_in_another_week_does_not_uncap(): void
    {
        // Marcado en la semana ANTERIOR: no aplica a esta.
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);
        $this->markDelivery($e, weekStart: '2026-05-25');

        $split = $this->calculator()->calculate($record, $e);

        $this->assertEqualsWithDelta(0.0, $split['velada_authorized'], 0.01, 'solo aplica la semana marcada');
    }

    public function test_report_shows_full_overtime_for_a_delivery_week(): void
    {
        // Mismo caso que "caps authorized hours at timecard" pero marcado como
        // entregas esa semana: las 5 h autorizadas se muestran COMPLETAS, no
        // topadas a las 2 h del timecard.
        $department = Department::factory()->create(['name' => 'Almacen Test', 'code' => 'ALMT']);
        $e = Employee::factory()->create(['status' => 'active', 'department_id' => $department->id]);

        AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '19:00:00', // 120 min detectados → 2h
            'status' => 'present',
        ]);

        $heType = CompensationType::factory()->fixed(50.00)->create([
            'code' => 'HE',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        Authorization::create([
            'employee_id' => $e->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $heType->id,
            'date' => self::DATE,
            'hours' => 5.0,
            'reason' => 'entrega',
            'status' => Authorization::STATUS_APPROVED,
        ]);
        $this->markDelivery($e);

        $report = app(WeeklyOvertimeReportService::class)->buildReport($department, Carbon::parse(self::DATE));
        $day = $report['rows'][0]['days'][self::DATE];

        $this->assertEqualsWithDelta(5.0, $day['overtime_hours'], 0.01, 'en semana de entregas las 5h se muestran completas');
    }
}
