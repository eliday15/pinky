<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Bono de velada y vale de cena (DECISIONES_NEGOCIO_2026-06-04.md §2):
 * se pagan por NOCHE REALMENTE TRABAJADA Y AUTORIZADA — máximo una vez por
 * (empleado, fecha) aunque existan autorizaciones duplicadas, solo cuando la
 * checada registró velada real (velada_hours > 0), y se suprimen por completo
 * en la ruta de CompensationTypes (la velada se paga por hora vía VEL y la
 * cena vía CENA) para no pagar doble.
 */
class NightShiftBonusTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'hourly_rate' => 100.00,
        ]);
    }

    private function monthlyPeriod(): PayrollPeriod
    {
        return PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
    }

    private function approvedNightShift(Employee $employee, string $date): Authorization
    {
        $user = User::factory()->create();

        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => $date,
            'hours' => 3,
            'reason' => 'velada',
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_bonus_paid_once_per_real_velada_night_despite_duplicate_authorizations(): void
    {
        $employee = $this->employee();

        // Dos filas de autorización para la MISMA noche (bug histórico de bulk).
        $this->approvedNightShift($employee, '2026-06-03');
        $this->approvedNightShift($employee, '2026-06-03');

        // Velada real en checadas esa noche.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'velada_hours' => 2.00,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(100.00, (float) $entry->night_shift_bonus, 0.01, '1 bono por noche, no por fila');
        $this->assertEqualsWithDelta(75.00, (float) $entry->dinner_allowance, 0.01, '1 cena por noche, no por fila');
        $this->assertSame(1, (int) $entry->night_shift_days);
    }

    public function test_approved_night_without_checkout_is_paid_by_authorization(): void
    {
        // Regla madre (Luis 2026-08-27, caso Luis Ortega 09/08): entró 22:00 a
        // velar y el reloj no registró su salida. Sin checada completa no hay
        // noche que medir: la autorización aprobada es la evidencia (misma
        // regla que el tiempo extra desde 2026-07-08).
        $employee = $this->employee();
        $this->approvedNightShift($employee, '2026-06-04');
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-04',
            'check_in' => '22:00:14',
            'check_out' => null,
            'status' => 'present',
            'velada_hours' => 0.00,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertSame(1, (int) $entry->night_shift_days, 'la noche aprobada sin salida checada se paga por autorización');
    }

    public function test_approved_night_with_full_checada_that_contradicts_it_is_not_paid(): void
    {
        // Checada COMPLETA que dice que salió antes de la ventana de velada
        // (18:45): el reloj prueba que no hubo noche — la aprobación sola no
        // basta. Vía autoservible: rechazar, o corregir la checada.
        $employee = $this->employee();
        $this->approvedNightShift($employee, '2026-06-04');
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-04',
            'check_in' => '08:43:35',
            'check_out' => '18:45:58',
            'status' => 'present',
            'velada_hours' => 0.00,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertSame(0, (int) $entry->night_shift_days);
    }

    public function test_no_bonus_without_real_velada_in_attendance(): void
    {
        $employee = $this->employee();

        // Autorización aprobada pero SIN velada real esa noche.
        $this->approvedNightShift($employee, '2026-06-04');
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-04',
            'status' => 'present',
            'velada_hours' => 0.00,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->night_shift_bonus, 0.01, 'sin velada trabajada no hay bono');
        $this->assertEqualsWithDelta(0.00, (float) $entry->dinner_allowance, 0.01);
        $this->assertSame(0, (int) $entry->night_shift_days);
    }

    public function test_approved_night_without_attendance_record_is_paid_by_authorization(): void
    {
        // Volteado a propósito (regla madre de Luis 2026-08-27): antes una
        // aprobada sin fila de asistencia pagaba 0. Sin timecard no hay noche
        // que medir — la aprobación es la evidencia (casos Lucina, Veronica:
        // exentas de checar / sin huellas con velada aprobada).
        $employee = $this->employee();
        $this->approvedNightShift($employee, '2026-06-05');

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertSame(1, (int) $entry->night_shift_days, 'sin fila de asistencia la noche aprobada se paga');
    }

    public function test_bonus_suppressed_on_compensation_type_path(): void
    {
        $employee = $this->employee();

        // Noche real autorizada que SÍ pagaría bono en la ruta legada.
        $this->approvedNightShift($employee, '2026-06-03');
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'velada_hours' => 2.00,
        ]);

        // Cualquier CompensationType activo cambia al empleado a la ruta de
        // conceptos: la velada se paga por hora (VEL) y la cena vía CENA, así
        // que el bono fijo legado debe suprimirse igual que la cena legada.
        $compType = CompensationType::factory()->fixed(50.00)->create([
            'code' => 'HEX1',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        $employee->compensationTypes()->attach($compType->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->night_shift_bonus, 0.01, 'bono legado suprimido con comp types');
        $this->assertEqualsWithDelta(0.00, (float) $entry->dinner_allowance, 0.01, 'cena legada suprimida con comp types');
    }
}
