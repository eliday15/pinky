<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Asistencia muestra la incidencia en lugar de "Ausente" (Dani 2026-08-13).
 *
 * Un día sin checadas cubierto por una incidencia APROBADA de vacaciones /
 * incapacidad / permiso se pinta con su incidencia (display_status) y no
 * cuenta en la tarjeta de Ausentes. Solo display: el status crudo del
 * registro sigue siendo 'absent' — nómina y reportes ya excluyen esos días
 * con su propia regla (typeJustifiesAbsence) y no se tocan.
 */
class AttendanceVacationDisplayTest extends FeatureTestCase
{
    private const DATE = '2026-06-08'; // lunes

    private function absentDay(Employee $employee): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::DATE,
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
        ]);
    }

    private function approvedIncident(Employee $employee, IncidentType $type): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'days_count' => 1,
        ]);
    }

    public function test_absent_day_covered_by_vacation_shows_vacaciones_and_leaves_status_raw(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create(['full_name' => 'Vacacionista Feliz']);
        $record = $this->absentDay($emp);
        $this->approvedIncident($emp, IncidentType::factory()->vacation()->create());

        $this->get(route('attendance.index', ['start_date' => self::DATE, 'end_date' => self::DATE, 'search' => 'Vacacionista']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.attendance_by_date.'.self::DATE.'.display_status', 'vacation')
                ->where('employees.data.0.attendance_by_date.'.self::DATE.'.status', 'absent')
                ->where('summary.absent', 0));

        $this->assertSame('absent', $record->fresh()->status, 'el status crudo no cambia');
    }

    public function test_sick_leave_shows_incapacidad(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create(['full_name' => 'Incapacitado Temporal']);
        $this->absentDay($emp);
        $this->approvedIncident($emp, IncidentType::factory()->sickLeave()->create());

        $this->get(route('attendance.index', ['start_date' => self::DATE, 'end_date' => self::DATE, 'search' => 'Incapacitado']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.attendance_by_date.'.self::DATE.'.display_status', 'sick_leave'));
    }

    public function test_uncovered_absent_still_shows_ausente_and_counts(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create(['full_name' => 'Faltista Real']);
        $this->absentDay($emp);

        $this->get(route('attendance.index', ['start_date' => self::DATE, 'end_date' => self::DATE, 'search' => 'Faltista']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.attendance_by_date.'.self::DATE.'.display_status', 'absent')
                ->where('summary.absent', 1));
    }

    public function test_pending_incident_does_not_cover(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create(['full_name' => 'Solicitante Pendiente']);
        $this->absentDay($emp);
        Incident::factory()->create([
            'employee_id' => $emp->id,
            'incident_type_id' => IncidentType::factory()->vacation()->create()->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'days_count' => 1,
            'status' => 'pending',
        ]);

        $this->get(route('attendance.index', ['start_date' => self::DATE, 'end_date' => self::DATE, 'search' => 'Solicitante']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.attendance_by_date.'.self::DATE.'.display_status', 'absent'));
    }
}
