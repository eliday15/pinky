<?php

namespace Tests\Feature\Console;

use App\Models\CompensationType;
use App\Models\Employee;
use Database\Seeders\AguinaldoConceptSeeder;
use Tests\FeatureTestCase;

class ImportEmployeeBirthdaysTest extends FeatureTestCase
{
    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bday').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_imports_birthdays_by_contpaqi_code_dmy(): void
    {
        $e = Employee::factory()->create(['contpaqi_code' => 'AEMB-740', 'birth_date' => null]);
        $csv = $this->csv("contpaqi_code,fecha_nacimiento\nAEMB-740,08/07/1990\n");

        $this->artisan('employees:import-birthdays', ['csv' => $csv])->assertExitCode(0);

        $this->assertSame('1990-07-08', $e->fresh()->birth_date?->format('Y-m-d'), 'd/m/Y = 8 jul');
        @unlink($csv);
    }

    public function test_matches_by_employee_number_and_iso_date(): void
    {
        $e = Employee::factory()->create(['employee_number' => 'E-500', 'birth_date' => null]);
        $csv = $this->csv("numero,nacimiento\nE-500,1985-03-15\n");

        $this->artisan('employees:import-birthdays', ['csv' => $csv])->assertExitCode(0);

        $this->assertSame('1985-03-15', $e->fresh()->birth_date?->format('Y-m-d'));
        @unlink($csv);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $e = Employee::factory()->create(['contpaqi_code' => 'X-1', 'birth_date' => null]);
        $csv = $this->csv("contpaqi_code,fecha_nacimiento\nX-1,01/01/1990\n");

        $this->artisan('employees:import-birthdays', ['csv' => $csv, '--dry-run' => true])->assertExitCode(0);

        $this->assertNull($e->fresh()->birth_date, 'dry-run no guarda');
        @unlink($csv);
    }

    public function test_unknown_code_and_bad_date_do_not_crash(): void
    {
        $csv = $this->csv("contpaqi_code,fecha_nacimiento\nNOPE-1,08/07/1990\nX-2,fecha-mala\n");

        $this->artisan('employees:import-birthdays', ['csv' => $csv])->assertExitCode(0);
        @unlink($csv);
    }

    public function test_aguinaldo_concept_seeder_creates_transfer_concept(): void
    {
        $this->seed(AguinaldoConceptSeeder::class);
        $this->seed(AguinaldoConceptSeeder::class); // idempotente: no duplica

        $count = CompensationType::where('code', 'AGUIN')->count();
        $this->assertSame(1, $count, 'idempotente: un solo concepto AGUIN');

        $aguin = CompensationType::where('code', 'AGUIN')->first();
        $this->assertTrue((bool) $aguin->pays_via_transfer, 'se paga por transferencia');
        $this->assertSame(CompensationType::PAYMENT_PERIOD_WEEKLY, $aguin->payment_period);
    }
}
