<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentType;
use Tests\FeatureTestCase;

/**
 * Un tipo de incidencia que gasta la bolsa de horas SIEMPRE es con goce de
 * sueldo (Dani 2026-07-15).
 *
 * Con is_paid=0 el motor lo contaba en permission_unpaid_days y restaba el día
 * COMPLETO del sueldo, además de gastarle las horas de vacaciones: el permiso
 * de 3 h costaba 1 día + 3 h. Las horas ya salen del saldo de vacaciones, que
 * es tiempo pagado.
 */
class VacationHoursTypeIsPaidTest extends FeatureTestCase
{
    public function test_creating_a_vacation_hours_type_forces_con_goce(): void
    {
        $this->actingAsAdmin();

        $this->post(route('incident-types.store'), [
            'name' => 'Horas a cuenta de vacaciones',
            'code' => 'HV2',
            'category' => 'permission',
            'is_paid' => false,
            'uses_vacation_hours' => true,
            'has_time_range' => true,
            'requires_approval' => true,
            'color' => '#3B82F6',
        ])->assertRedirect(route('incident-types.index'));

        $this->assertTrue(
            IncidentType::where('code', 'HV2')->firstOrFail()->is_paid,
            'un tipo que gasta la bolsa no puede quedar sin goce de sueldo',
        );
    }

    public function test_updating_a_vacation_hours_type_forces_con_goce(): void
    {
        $this->actingAsAdmin();

        $type = IncidentType::create([
            'name' => 'Horas a cuenta de vacaciones',
            'code' => 'HV3',
            'category' => 'permission',
            'is_paid' => false,
            'uses_vacation_hours' => true,
            'color' => '#3B82F6',
        ]);

        $this->put(route('incident-types.update', $type), [
            'name' => 'Horas a cuenta de vacaciones',
            'code' => 'HV3',
            'category' => 'permission',
            'is_paid' => false,
            'uses_vacation_hours' => true,
            'has_time_range' => true,
            'requires_approval' => true,
            'color' => '#3B82F6',
        ])->assertRedirect(route('incident-types.index'));

        $this->assertTrue($type->fresh()->is_paid, 'el update tampoco puede dejarlo sin goce');
    }

    public function test_a_normal_unpaid_permission_stays_unpaid(): void
    {
        $this->actingAsAdmin();

        $this->post(route('incident-types.store'), [
            'name' => 'Permiso sin goce de prueba',
            'code' => 'PSG2',
            'category' => 'permission',
            'is_paid' => false,
            'uses_vacation_hours' => false,
            'requires_approval' => true,
            'color' => '#6366F1',
        ])->assertRedirect(route('incident-types.index'));

        $this->assertFalse(
            IncidentType::where('code', 'PSG2')->firstOrFail()->is_paid,
            'la regla solo aplica a los tipos que gastan la bolsa',
        );
    }
}
