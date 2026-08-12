<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Services\WeekendHolidayAutoApprovalService;
use Tests\FeatureTestCase;

/**
 * Auto-aprobación para empleados "No checa" (Dani 2026-08-12, supervisoras de
 * Calidad).
 *
 * Un exento de checador (is_attendance_exempt) nunca va a tener marcas que
 * respalden nada — su captura aprobada ES la fuente de verdad en nómina. Por
 * eso su TIEMPO EXTRA capturado se aprueba solo, y su FIN DE SEMANA / FESTIVO
 * también, siempre que el día realmente sea sábado/domingo o festivo. La
 * velada de exentos sigue a revisión humana (no se pidió automatizarla).
 */
class ExemptAutoApprovalTest extends FeatureTestCase
{
    private function exemptEmployee(): Employee
    {
        return Employee::factory()->create([
            'is_attendance_exempt' => true,
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '16:30',
            ])->id,
        ]);
    }

    public function test_exempt_overtime_auto_approves_without_punches(): void
    {
        // Supervisor (captura, NO aprueba) y CERO registros de asistencia:
        // para un exento la captura se aprueba sola de todos modos.
        $this->actingAsSupervisor();
        $emp = $this->exemptEmployee();

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08', // lunes
            'start_time' => '16:30',
            'end_time' => '18:30',
            'hours' => 2.0,
            'reason' => 'TE de supervisora de Calidad (no checa)',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_non_exempt_overtime_without_punches_stays_pending(): void
    {
        $this->actingAsSupervisor();
        $emp = Employee::factory()->create([
            'is_attendance_exempt' => false,
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '16:30',
            ])->id,
        ]);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '18:30',
            'hours' => 2.0,
            'reason' => 'sin checada que lo respalde',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_exempt_velada_stays_pending(): void
    {
        // La velada NO entra en el bypass de exentos: sigue a revisión humana.
        $this->actingAsSupervisor();
        $emp = $this->exemptEmployee();

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => '2026-06-08',
            'start_time' => '22:00',
            'end_time' => '02:00',
            'hours' => 4.0,
            'reason' => 'velada de exenta',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_exempt_weekend_concept_qualifies_on_saturday_without_record(): void
    {
        $emp = $this->exemptEmployee();
        $auth = Authorization::factory()->special()->create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'date' => '2026-06-06', // sábado
            'status' => Authorization::STATUS_PENDING,
            'start_time' => null,
            'end_time' => null,
            'hours' => 1.0,
            'compensation_type_id' => CompensationType::factory()->create([
                'application_mode' => 'per_day',
                'authorization_type' => 'special',
                'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
            ])->id,
        ]);

        $this->assertTrue(app(WeekendHolidayAutoApprovalService::class)->qualifies($auth));
    }

    public function test_exempt_weekend_concept_does_not_qualify_on_weekday(): void
    {
        // Sin marcas, manda el calendario: un "fin de semana" capturado en
        // martes no se aprueba solo ni para exentos.
        $emp = $this->exemptEmployee();
        $auth = Authorization::factory()->special()->create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'date' => '2026-06-09', // martes
            'status' => Authorization::STATUS_PENDING,
            'start_time' => null,
            'end_time' => null,
            'hours' => 1.0,
            'compensation_type_id' => CompensationType::factory()->create([
                'application_mode' => 'per_day',
                'authorization_type' => 'special',
                'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
            ])->id,
        ]);

        $this->assertFalse(app(WeekendHolidayAutoApprovalService::class)->qualifies($auth));
    }
}
