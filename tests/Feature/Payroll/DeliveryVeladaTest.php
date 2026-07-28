<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\DeliveryPeriod;
use App\Models\Employee;
use App\Models\User;
use App\Services\Reports\WeeklyOvertimeReportService;
use App\Services\VeladaCalculatorService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Personal de entregas por RANGO de fechas (Dani 2026-07-28).
 *
 * RRHH marca de qué fecha a qué fecha salió cada colaborador a entregas. En esas
 * fechas su velada y tiempo extra AUTORIZADOS se pagan/reflejan completos, sin
 * topar contra la checada (que no los alcanza a registrar porque andan en la
 * calle). Reporte (lee velada_hours) y nómina (lee velada_authorized_hours)
 * coinciden porque el destope vive en VeladaCalculatorService.
 */
class DeliveryVeladaTest extends FeatureTestCase
{
    private const DATE = '2026-06-03'; // miércoles

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

    private function markDelivery(Employee $employee, string $from = '2026-06-01', string $to = '2026-06-07'): void
    {
        DeliveryPeriod::create(['employee_id' => $employee->id, 'start_date' => $from, 'end_date' => $to]);
    }

    public function test_velada_is_capped_to_zero_without_a_delivery_mark(): void
    {
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);

        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate($record, $e)['velada_authorized'], 0.01);
    }

    public function test_delivery_range_pays_full_authorized_velada(): void
    {
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);
        $this->markDelivery($e);

        $split = $this->calculator()->calculate($record, $e);

        $this->assertEqualsWithDelta(3.0, $split['velada_authorized'], 0.01);
        $this->assertEqualsWithDelta(3.0, $split['velada_hours'], 0.01, 'reporte y recibo leen la misma cifra');
    }

    public function test_delivery_range_pays_velada_even_without_checkout(): void
    {
        // Caso real (Pedro/Norma): entrada sin salida → antes velada = 0.
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e, checkOut: null);
        $this->approveVelada($e, 2.5);
        $this->markDelivery($e);

        $this->assertEqualsWithDelta(2.5, $this->calculator()->calculate($record, $e)['velada_authorized'], 0.01);
    }

    public function test_a_date_outside_the_range_does_not_uncap(): void
    {
        // El rango NO cubre la fecha (06-03): la velada sigue topada.
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);
        $this->markDelivery($e, from: '2026-05-25', to: '2026-05-31');

        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate($record, $e)['velada_authorized'], 0.01);
    }

    public function test_a_single_day_range_covers_that_day(): void
    {
        // Rango de un solo día (06-03 a 06-03) sí cubre la fecha.
        $e = Employee::factory()->create(['status' => 'active']);
        $record = $this->dayRecord($e);
        $this->approveVelada($e, 3.0);
        $this->markDelivery($e, from: self::DATE, to: self::DATE);

        $this->assertEqualsWithDelta(3.0, $this->calculator()->calculate($record, $e)['velada_authorized'], 0.01);
    }

    public function test_report_shows_full_overtime_for_a_delivery_range(): void
    {
        // 5 h autorizadas con timecard que solo detecta 2 h: en rango de entregas
        // se muestran COMPLETAS (no topadas).
        $department = Department::factory()->create(['name' => 'Almacen Test', 'code' => 'ALMT']);
        $e = Employee::factory()->create(['status' => 'active', 'department_id' => $department->id]);

        AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '19:00:00', // 120 min → 2h
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

        $this->assertEqualsWithDelta(5.0, $day['overtime_hours'], 0.01, 'en rango de entregas las 5h se muestran completas');
    }
}
