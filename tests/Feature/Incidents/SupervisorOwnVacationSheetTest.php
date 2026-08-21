<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * El equipo del jefe lo incluye a él mismo (Dani 2026-08-19): un jefe de
 * departamento (rol supervisor, incidents.view_team) también ve en el listado
 * SU propia hoja de vacaciones y puede descargar el formato de media carta —
 * antes solo veía las de sus subordinados. El scope no se abre de más: las
 * hojas de empleados fuera de su árbol siguen en 403.
 */
class SupervisorOwnVacationSheetTest extends FeatureTestCase
{
    /**
     * @return array{0: \App\Models\User, 1: Employee, 2: Employee, 3: Employee}
     */
    private function makeTeam(): array
    {
        $user = $this->supervisorUser();
        $jefe = Employee::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Jefa De Area',
            'status' => 'active',
        ]);
        $subordinada = Employee::factory()->create([
            'supervisor_id' => $jefe->id,
            'full_name' => 'Costurera Del Equipo',
            'status' => 'active',
        ]);
        $ajena = Employee::factory()->create([
            'full_name' => 'Empleada De Otro Depto',
            'status' => 'active',
        ]);

        return [$user, $jefe, $subordinada, $ajena];
    }

    private function vacationSheet(Employee $employee): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => IncidentType::factory()->vacation()->create()->id,
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-10',
            'days_count' => 3,
        ]);
    }

    public function test_supervisor_sees_own_vacation_sheet_in_index(): void
    {
        [$user, $jefe] = $this->makeTeam();
        $this->vacationSheet($jefe);
        $this->actingAs($user);

        $this->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.data.0.employee.id', $jefe->id));
    }

    public function test_supervisor_downloads_own_vacation_form(): void
    {
        [$user, $jefe] = $this->makeTeam();
        $sheet = $this->vacationSheet($jefe);
        $this->actingAs($user);

        $this->get(route('incidents.vacationForm', $sheet))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_supervisor_still_downloads_subordinate_form(): void
    {
        [$user, , $subordinada] = $this->makeTeam();
        $sheet = $this->vacationSheet($subordinada);
        $this->actingAs($user);

        $this->get(route('incidents.vacationForm', $sheet))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_supervisor_cannot_download_outside_team_form(): void
    {
        [$user, , , $ajena] = $this->makeTeam();
        $sheet = $this->vacationSheet($ajena);
        $this->actingAs($user);

        $this->get(route('incidents.vacationForm', $sheet))
            ->assertForbidden();
    }
}
