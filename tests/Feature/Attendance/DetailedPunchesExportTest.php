<?php

namespace Tests\Feature\Attendance;

use App\Exports\DetailedPunchesExport;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use Tests\FeatureTestCase;

/**
 * Descarga de checadas detalladas (Dani 2026-06-30): una fila por CADA marca del
 * día, con la hora en AM/PM, acotada al personal del encargado.
 */
class DetailedPunchesExportTest extends FeatureTestCase
{
    public function test_export_produces_one_row_per_punch_with_ampm_and_labels(): void
    {
        $dept = Department::factory()->create(['name' => 'Almacén PT']);
        $emp = Employee::factory()->create([
            'department_id' => $dept->id,
            'employee_number' => 'EMP-0014',
            'status' => 'active',
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-19',
            'raw_punches' => [
                ['time' => '05:01:00', 'type' => 'in', 'method' => 'fingerprint'],
                ['time' => '07:44:00', 'type' => 'punch', 'method' => 'fingerprint'],
                ['time' => '22:04:00', 'type' => 'out', 'method' => 'fingerprint'],
            ],
        ]);

        $rows = (new DetailedPunchesExport('2026-06-19', '2026-06-19'))->array();

        // Encabezado + 3 marcas.
        $this->assertCount(4, $rows);
        $this->assertSame(['No. Empleado', 'Nombre', 'Departamento', 'Fecha', 'Hora', 'Tipo', 'Método'], $rows[0]);

        $this->assertSame('EMP-0014', $rows[1][0]);
        $this->assertSame('Almacén PT', $rows[1][2]);
        $this->assertSame('19/06/2026', $rows[1][3]);

        // 05:01 -> 5:01 a. m. (entrada); 07:44 marca; 22:04 -> 10:04 p. m. (salida).
        $this->assertSame('5:01 a. m.', $rows[1][4]);
        $this->assertSame('entrada', $rows[1][5]);
        $this->assertSame('marca', $rows[2][5]);
        $this->assertSame('10:04 p. m.', $rows[3][4]);
        $this->assertSame('salida', $rows[3][5]);
    }

    public function test_export_is_scoped_to_given_employee_ids(): void
    {
        $emp1 = Employee::factory()->create(['status' => 'active']);
        $emp2 = Employee::factory()->create(['status' => 'active']);
        foreach ([$emp1, $emp2] as $e) {
            AttendanceRecord::factory()->create([
                'employee_id' => $e->id,
                'work_date' => '2026-06-19',
                'raw_punches' => [['time' => '08:00:00', 'type' => 'in']],
            ]);
        }

        $rows = (new DetailedPunchesExport('2026-06-19', '2026-06-19', null, collect([$emp1->id])))->array();

        // Encabezado + solo la marca de emp1.
        $this->assertCount(2, $rows);
        $this->assertSame($emp1->employee_number, $rows[1][0]);
    }

    public function test_route_downloads_for_admin(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create(['status' => 'active']);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-19',
            'raw_punches' => [['time' => '08:00:00', 'type' => 'in']],
        ]);

        $this->get(route('attendance.export-punches', [
            'start_date' => '2026-06-19',
            'end_date' => '2026-06-19',
        ]))->assertOk()
            ->assertDownload('checadas_detalladas_2026-06-19_2026-06-19.xlsx');
    }
}
