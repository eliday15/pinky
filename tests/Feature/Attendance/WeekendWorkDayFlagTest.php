<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Employee::isWeekendWorkDay decide is_weekend_work durante el sync, y
 * isObligatoryWorkDay decide si un día puede generar falta.
 *
 * Regla vigente (Dani 2026-07-07/08): en TODOS los departamentos, CUALQUIER
 * sábado o domingo cuenta como fin de semana y NO es obligatorio (faltar o salir
 * temprano un fin de semana ya no es falta), sin importar el horario. El pago
 * sigue dependiendo de la autorización FIN aprobada.
 */
class WeekendWorkDayFlagTest extends FeatureTestCase
{
    private const SATURDAY = '2026-06-20';
    private const SUNDAY = '2026-06-21';
    private const MONDAY = '2026-06-22';

    private function employeeIn(?int $weekendUnitHours, array $workingDays): Employee
    {
        $dept = Department::factory()->create(['weekend_unit_hours' => $weekendUnitHours]);

        return Employee::factory()->create([
            'department_id' => $dept->id,
            'status' => 'active',
            'schedule_overrides' => ['working_days' => $workingDays],
        ]);
    }

    public function test_any_weekend_day_counts_as_weekend_in_all_departments(): void
    {
        // Depto normal con horario L-S: sábado Y domingo cuentan como fin de
        // semana aunque el horario incluya el sábado (Dani 2026-07-07).
        $emp = $this->employeeIn(null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);

        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SATURDAY)), 'sábado = fin de semana en todos los deptos');
        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SUNDAY)), 'domingo = fin de semana');
        $this->assertFalse($emp->isWeekendWorkDay(Carbon::parse(self::MONDAY)), 'un lunes nunca es fin de semana');
    }

    public function test_almacen_also_counts_any_weekend_day(): void
    {
        $emp = $this->employeeIn(6, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);

        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SATURDAY)));
        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SUNDAY)));
    }

    public function test_weekends_are_never_obligatory(): void
    {
        // Aunque el horario incluya sábado (y domingo), no son obligatorios: no
        // pueden generar falta.
        $emp = $this->employeeIn(null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);

        $this->assertFalse($emp->isObligatoryWorkDay(Carbon::parse(self::SATURDAY)), 'sábado no es obligatorio');
        $this->assertFalse($emp->isObligatoryWorkDay(Carbon::parse(self::SUNDAY)), 'domingo no es obligatorio');
    }

    public function test_weekday_obligatory_only_when_in_schedule(): void
    {
        // Entre semana sí es obligatorio si está en el horario; si es día de
        // descanso (no está en working_days), no lo es.
        $lunToVie = $this->employeeIn(null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        $this->assertTrue($lunToVie->isObligatoryWorkDay(Carbon::parse(self::MONDAY)), 'lunes en horario = obligatorio');

        $martesAJueves = $this->employeeIn(null, ['tuesday', 'wednesday', 'thursday']);
        $this->assertFalse($martesAJueves->isObligatoryWorkDay(Carbon::parse(self::MONDAY)), 'lunes fuera de horario = descanso');
    }
}
