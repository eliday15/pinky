<?php

namespace Tests\Feature\Employees;

use App\Models\Department;
use App\Models\Employee;
use Tests\FeatureTestCase;

/**
 * Empresa (razón social / canal de pago del "Reporte al contador") derivada de
 * la regla del negocio (Elias 2026-07-23): Taller = AVL (mande la prueba o no);
 * del resto, prueba = POR FUERA y los demás = VP. Se recalcula en cada guardado.
 */
class EmpresaAssignmentTest extends FeatureTestCase
{
    private function taller(): Department
    {
        return Department::factory()->create(['name' => 'Taller Adriana']);
    }

    private function otherDept(): Department
    {
        return Department::factory()->create(['name' => 'Corte']);
    }

    public function test_taller_employee_is_avl_even_on_trial(): void
    {
        $e = Employee::factory()->create([
            'department_id' => $this->taller()->id,
            'is_trial_period' => true,
        ]);

        $this->assertSame('AVL', $e->fresh()->empresa, 'todo el taller es AVL, aun en prueba');
    }

    public function test_taller_employee_is_avl_when_formalized(): void
    {
        $e = Employee::factory()->create([
            'department_id' => $this->taller()->id,
            'is_trial_period' => false,
        ]);

        $this->assertSame('AVL', $e->fresh()->empresa);
    }

    public function test_trial_employee_outside_taller_is_por_fuera(): void
    {
        $e = Employee::factory()->create([
            'department_id' => $this->otherDept()->id,
            'is_trial_period' => true,
        ]);

        $this->assertSame('POR_FUERA', $e->fresh()->empresa);
    }

    public function test_regular_employee_is_vp(): void
    {
        $e = Employee::factory()->create([
            'department_id' => $this->otherDept()->id,
            'is_trial_period' => false,
        ]);

        $this->assertSame('VP', $e->fresh()->empresa);
    }

    public function test_moving_to_taller_updates_empresa(): void
    {
        $e = Employee::factory()->create([
            'department_id' => $this->otherDept()->id,
            'is_trial_period' => false,
        ]);
        $this->assertSame('VP', $e->fresh()->empresa);

        $e->update(['department_id' => $this->taller()->id]);

        $this->assertSame('AVL', $e->fresh()->empresa, 'cambiar de depto a Taller lo vuelve AVL');
    }

    public function test_ending_trial_outside_taller_moves_to_vp(): void
    {
        $e = Employee::factory()->create([
            'department_id' => $this->otherDept()->id,
            'is_trial_period' => true,
        ]);
        $this->assertSame('POR_FUERA', $e->fresh()->empresa);

        $e->update(['is_trial_period' => false]);

        $this->assertSame('VP', $e->fresh()->empresa, 'al formalizar (fuera de taller) pasa a VP');
    }
}
