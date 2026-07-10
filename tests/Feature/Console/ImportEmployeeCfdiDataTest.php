<?php

namespace Tests\Feature\Console;

use App\Models\Employee;
use Tests\FeatureTestCase;

class ImportEmployeeCfdiDataTest extends FeatureTestCase
{
    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cfdi').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_imports_curp_rfc_clabe_and_uppercases(): void
    {
        $e = Employee::factory()->create(['contpaqi_code' => 'AEMB-740', 'curp' => null, 'rfc' => null]);
        $csv = $this->csv("contpaqi_code,curp,rfc,clabe,banco\nAEMB-740,xaxx010101hdfxxx01,xaxx010101000,012180001234567897,012\n");

        $this->artisan('employees:import-cfdi-data', ['csv' => $csv])->assertExitCode(0);

        $e->refresh();
        $this->assertSame('XAXX010101HDFXXX01', $e->curp, 'CURP en mayúsculas');
        $this->assertSame('XAXX010101000', $e->rfc);
        $this->assertSame('012180001234567897', $e->clabe);
        $this->assertSame('012', $e->bank_code);
        @unlink($csv);
    }

    public function test_empty_cells_do_not_overwrite_existing_data(): void
    {
        $e = Employee::factory()->create(['contpaqi_code' => 'X-1', 'curp' => 'CURPEXISTENTE01HDF', 'rfc' => null]);
        $csv = $this->csv("contpaqi_code,curp,rfc\nX-1,,NUEVO010101RF0\n");

        $this->artisan('employees:import-cfdi-data', ['csv' => $csv])->assertExitCode(0);

        $e->refresh();
        $this->assertSame('CURPEXISTENTE01HDF', $e->curp, 'celda vacía no pisa lo capturado');
        $this->assertSame('NUEVO010101RF0', $e->rfc);
        @unlink($csv);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $e = Employee::factory()->create(['contpaqi_code' => 'X-2', 'curp' => null]);
        $csv = $this->csv("contpaqi_code,curp\nX-2,XAXX010101HDFXXX02\n");

        $this->artisan('employees:import-cfdi-data', ['csv' => $csv, '--dry-run' => true])->assertExitCode(0);

        $this->assertNull($e->fresh()->curp);
        @unlink($csv);
    }
}
