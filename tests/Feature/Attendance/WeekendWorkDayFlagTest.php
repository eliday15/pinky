<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Employee::isWeekendWorkDay decide is_weekend_work durante el sync (y el
 * backfill). Regla (Dani 2026-06-25): en Almacén PT —y cualquier depto con
 * weekend_unit_hours— CUALQUIER sábado/domingo trabajado es fin de semana,
 * aunque el horario lo incluya; los demás departamentos conservan la regla de
 * "solo si cae fuera del horario". El pago sigue dependiendo de la autorización
 * FIN aprobada, así que esta bandera solo decide si el día se OFRECE como fin de
 * semana en "Cargar desde checadas".
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

    public function test_almacen_counts_any_weekend_day_regardless_of_schedule(): void
    {
        // Almacén PT con horario L-S: sábado Y domingo cuentan como fin de semana.
        $emp = $this->employeeIn(6, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);

        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SATURDAY)), 'sábado en Almacén = fin de semana');
        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SUNDAY)), 'domingo en Almacén = fin de semana');
        $this->assertFalse($emp->isWeekendWorkDay(Carbon::parse(self::MONDAY)), 'un lunes nunca es fin de semana');
    }

    public function test_almacen_sunday_counts_even_when_schedule_wrongly_includes_sunday(): void
    {
        // Caso Arturo: horario mal configurado que incluye el domingo. En Almacén
        // el domingo sigue contando como fin de semana (antes quedaba en false y no
        // aparecía en Autorizaciones).
        $emp = $this->employeeIn(6, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);

        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SUNDAY)));
    }

    public function test_non_unit_department_keeps_schedule_based_weekend(): void
    {
        // Depto normal (sin weekend_unit_hours) con horario L-S: el sábado es día
        // laborable (NO fin de semana); el domingo, fuera de horario, sí lo es.
        $emp = $this->employeeIn(null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);

        $this->assertFalse($emp->isWeekendWorkDay(Carbon::parse(self::SATURDAY)), 'sábado L-S en depto normal = día laborable');
        $this->assertTrue($emp->isWeekendWorkDay(Carbon::parse(self::SUNDAY)), 'domingo fuera de horario = fin de semana');
    }
}
