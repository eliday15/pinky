<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use Tests\FeatureTestCase;

/**
 * Aprobadores nombrados por concepto (petición 2026-07-21: "que haya quien
 * puede aprobar esta compensación, para que no cualquier usuario pueda").
 *
 * Reglas acordadas:
 * - Sin lista = SIN restricción: se comporta como siempre. Esto deja intactos
 *   los ~30 conceptos que ya existen.
 * - Con lista = sólo esos usuarios (más el superadmin, que nunca se queda
 *   fuera) aprueban o rechazan ese concepto.
 * - Estar en la lista FACULTA para ese concepto aunque el usuario no tenga el
 *   permiso general `authorizations.approve`.
 * - Sólo el superadmin edita la lista.
 */
class ConceptApproverListTest extends FeatureTestCase
{
    /** Concepto de compensación con los aprobadores indicados. */
    private function concept(array $approvers = []): CompensationType
    {
        $concept = CompensationType::factory()->create();

        if ($approvers !== []) {
            $concept->approvers()->sync(collect($approvers)->pluck('id')->all());
        }

        return $concept->fresh();
    }

    /** Autorización pendiente de otro empleado para ese concepto. */
    private function authorization(CompensationType $concept): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'compensation_type_id' => $concept->id,
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_sin_lista_el_concepto_no_restringe_a_nadie(): void
    {
        $admin = $this->createUser('admin');
        $authorization = $this->authorization($this->concept());

        $this->assertTrue($admin->can('approve', $authorization));
        $this->assertTrue($admin->can('reject', $authorization));
    }

    public function test_con_lista_un_admin_fuera_de_la_lista_no_puede_aprobar_ni_rechazar(): void
    {
        $nombrado = $this->createUser('rrhh');
        $admin = $this->createUser('admin');
        $authorization = $this->authorization($this->concept([$nombrado]));

        $this->assertFalse($admin->can('approve', $authorization));
        $this->assertFalse($admin->can('reject', $authorization));
    }

    public function test_con_lista_el_usuario_nombrado_si_puede_aprobar_y_rechazar(): void
    {
        $nombrado = $this->createUser('rrhh');
        $authorization = $this->authorization($this->concept([$nombrado]));

        $this->assertTrue($nombrado->can('approve', $authorization));
        $this->assertTrue($nombrado->can('reject', $authorization));
    }

    public function test_el_superadmin_aprueba_aunque_no_este_en_la_lista(): void
    {
        $nombrado = $this->createUser('rrhh');
        $superadmin = $this->createUser('superadmin');
        $authorization = $this->authorization($this->concept([$nombrado]));

        $this->assertTrue($superadmin->can('approve', $authorization));
        $this->assertTrue($superadmin->can('reject', $authorization));
    }

    public function test_estar_en_la_lista_faculta_aunque_no_tenga_el_permiso_general(): void
    {
        // 'employee' no trae authorizations.approve; la lista se lo otorga
        // para ESTE concepto.
        $sinPermiso = $this->createUser('employee');
        $authorization = $this->authorization($this->concept([$sinPermiso]));

        $this->assertFalse($sinPermiso->hasPermissionTo('authorizations.approve'));
        $this->assertTrue($sinPermiso->can('approve', $authorization));

        // Pero sólo para el concepto donde lo nombraron.
        $otro = $this->authorization($this->concept());
        $this->assertFalse($sinPermiso->can('approve', $otro));
    }

    public function test_nadie_aprueba_lo_propio_aunque_este_en_la_lista(): void
    {
        $nombrado = $this->createUser('rrhh');
        $empleado = Employee::factory()->create(['user_id' => $nombrado->id]);
        $concept = $this->concept([$nombrado]);

        $propia = Authorization::factory()->create([
            'employee_id' => $empleado->id,
            'compensation_type_id' => $concept->id,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->assertFalse($nombrado->can('approve', $propia));
    }

    public function test_solo_el_superadmin_guarda_la_lista_de_aprobadores(): void
    {
        $admin = $this->createUser('admin');
        $candidato = $this->createUser('rrhh');
        $concept = $this->concept();

        $this->actingAs($admin)
            ->put(route('compensation-types.update', $concept), [
                'name' => $concept->name,
                'code' => $concept->code,
                'calculation_type' => 'fixed',
                'fixed_amount' => 100,
                'application_mode' => 'one_time',
                'priority' => 0,
                'approver_ids' => [$candidato->id],
            ])
            ->assertRedirect();

        // El admin no es superadmin: la lista queda intacta (vacía).
        $this->assertSame(0, $concept->fresh()->approvers()->count());
    }

    public function test_el_superadmin_si_guarda_la_lista_de_aprobadores(): void
    {
        $superadmin = $this->createUser('superadmin');
        $candidato = $this->createUser('rrhh');
        $concept = $this->concept();

        $this->actingAs($superadmin)
            ->put(route('compensation-types.update', $concept), [
                'name' => $concept->name,
                'code' => $concept->code,
                'calculation_type' => 'fixed',
                'fixed_amount' => 100,
                'application_mode' => 'one_time',
                'priority' => 0,
                'approver_ids' => [$candidato->id],
            ])
            ->assertRedirect();

        $this->assertTrue($concept->fresh()->approvers->contains('id', $candidato->id));
    }

    public function test_el_superadmin_puede_quitar_el_candado_vaciando_la_lista(): void
    {
        $superadmin = $this->createUser('superadmin');
        $nombrado = $this->createUser('rrhh');
        $admin = $this->createUser('admin');
        $concept = $this->concept([$nombrado]);

        $this->actingAs($superadmin)
            ->put(route('compensation-types.update', $concept), [
                'name' => $concept->name,
                'code' => $concept->code,
                'calculation_type' => 'fixed',
                'fixed_amount' => 100,
                'application_mode' => 'one_time',
                'priority' => 0,
                'approver_ids' => [],
            ])
            ->assertRedirect();

        $this->assertSame(0, $concept->fresh()->approvers()->count());
        $this->assertTrue($admin->can('approve', $this->authorization($concept->fresh())));
    }
}
