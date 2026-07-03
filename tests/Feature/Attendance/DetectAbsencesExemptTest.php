<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Illuminate\Support\Carbon;
use Tests\FeatureTestCase;

/**
 * detectAbsences() genera faltas automáticas para empleados que no marcaron en
 * un día laborable. Los empleados exentos (no checan) DEBEN quedar excluidos:
 * de lo contrario recibirían una falta cada día y su sueldo se descontaría a 0.
 */
class DetectAbsencesExemptTest extends FeatureTestCase
{
    public function test_detect_absences_skips_attendance_exempt_employees(): void
    {
        // Martes 2026-06-09 → ayer = lunes 2026-06-08 (día laborable L-V, no festivo).
        $this->travelTo(Carbon::parse('2026-06-09 10:00:00'));

        $schedule = Schedule::factory()->create([
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);
        $normal = Employee::factory()->create([
            'status' => 'active',
            'is_attendance_exempt' => false,
            'schedule_id' => $schedule->id,
        ]);
        $exempt = Employee::factory()->create([
            'status' => 'active',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
            'schedule_id' => $schedule->id,
        ]);

        $service = app(ZktecoSyncService::class);
        $method = new \ReflectionMethod($service, 'detectAbsences');
        $method->setAccessible(true);
        $yesterday = Carbon::yesterday();
        $method->invoke($service, $yesterday->copy(), $yesterday->copy());

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $normal->id,
            'work_date' => '2026-06-08',
            'status' => 'absent',
        ]);
        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $exempt->id,
            'work_date' => '2026-06-08',
        ]);

        $this->travelBack();
    }

    public function test_markMissingEmployeesAsInactive_never_touches_exempt_without_zkteco(): void
    {
        $exempt = Employee::factory()->create([
            'status' => 'active',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
        ]);

        $service = app(ZktecoSyncService::class);
        $method = new \ReflectionMethod($service, 'markMissingEmployeesAsInactive');
        $method->setAccessible(true);
        // Un empleado con ZKTeco "visto" para forzar el barrido de los no vistos.
        $method->invoke($service, [999999]);

        $this->assertSame('active', $exempt->fresh()->status, 'exento sin ZKTeco nunca se inactiva');
    }
}
