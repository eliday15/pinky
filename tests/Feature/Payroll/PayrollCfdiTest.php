<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollCfdi;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\Cfdi\FakePacDriver;
use App\Services\Cfdi\PacProviderInterface;
use App\Services\Cfdi\PayrollCfdiBuilder;
use App\Services\Cfdi\PayrollCfdiService;
use App\Services\PayrollCalculatorService;
use Database\Seeders\FiscalSettingsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Timbrado CFDI 4.0 de nómina (Fase 3): builder (payload del complemento
 * 1.2), servicio con PAC falso, idempotencia y candado de recálculo.
 */
class PayrollCfdiTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiscalSettingsSeeder::class);
        SystemSetting::updateOrCreate(['key' => 'company_zip_code'], ['value' => '53000', 'type' => 'string', 'group' => 'empresa', 'label' => 'CP']);
        Storage::fake();
    }

    private function formalized(array $attrs = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'daily_salary' => 500.00,
            'hire_date' => '2024-03-01',
            'is_imss_enrolled' => true,
            'imss_number' => '12345678901',
            'is_trial_period' => false,
            'is_attendance_exempt' => true,
            'rfc' => 'XAXX010101000',
            'curp' => 'XAXX010101HDFXXX01',
            'address_zip' => '53000',
            'sdi' => 524.65,
            'sbc' => 524.65,
        ], $attrs));
    }

    private function weeklyEntry(Employee $employee)
    {
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'payment_date' => '2026-06-12',
            'status' => 'draft',
        ]);
        $entry = app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $employee);
        $period->update(['status' => 'approved']);

        return [$period, $entry];
    }

    public function test_builder_produces_valid_payload(): void
    {
        [, $entry] = $this->weeklyEntry($this->formalized());
        $payload = app(PayrollCfdiBuilder::class)->build($entry->fresh(['employee.department', 'employee.position', 'payrollPeriod']));

        $this->assertSame('N', $payload['CfdiType']);
        $this->assertSame('VPI731127SV8', $payload['Issuer']['Rfc']);
        $this->assertSame('XAXX010101000', $payload['Receiver']['Rfc']);
        $this->assertSame('C4121138109', $payload['Complemento']['Payroll']['Issuer']['EmployerRegistration']);
        // Sueldo como percepción 001 gravada completa.
        $sueldo = collect($payload['Complemento']['Payroll']['Perceptions']['Details'])->firstWhere('PerceptionType', '001');
        $this->assertNotNull($sueldo);
        $this->assertEqualsWithDelta(3500.00, $sueldo['TaxedAmount'], 0.01, 'SD 500 × 7');
        $this->assertSame(0.0, (float) $sueldo['ExemptAmount']);
    }

    public function test_builder_reports_missing_fields(): void
    {
        [, $entry] = $this->weeklyEntry($this->formalized(['curp' => null, 'rfc' => null]));
        $missing = app(PayrollCfdiBuilder::class)->missingFields($entry->fresh(['employee']));

        $this->assertContains('curp', $missing);
        $this->assertContains('rfc', $missing);
    }

    public function test_stamp_entry_via_fake_pac_stores_xml_and_uuid(): void
    {
        [, $entry] = $this->weeklyEntry($this->formalized());
        $service = app(PayrollCfdiService::class);

        $cfdi = $service->stampEntry($entry->fresh(['employee.department', 'employee.position', 'payrollPeriod']));

        $this->assertSame(PayrollCfdi::STATUS_STAMPED, $cfdi->status);
        $this->assertNotEmpty($cfdi->uuid);
        Storage::assertExists($cfdi->xml_path);

        // Idempotente: re-timbrar no crea otro CFDI ni re-llama al PAC.
        $again = $service->stampEntry($entry->fresh(['employee.department', 'employee.position', 'payrollPeriod']));
        $this->assertSame($cfdi->id, $again->id);
        $this->assertCount(1, app(FakePacDriver::class)->stamped);
    }

    public function test_pac_error_marks_cfdi_as_error(): void
    {
        [, $entry] = $this->weeklyEntry($this->formalized());
        $fake = app(FakePacDriver::class);
        $fake->failWith = new \RuntimeException('Facturama HTTP 400: RFC inválido');

        try {
            app(PayrollCfdiService::class)->stampEntry($entry->fresh(['employee.department', 'employee.position', 'payrollPeriod']));
            $this->fail('debió lanzar');
        } catch (\RuntimeException) {
            // esperado
        }

        $cfdi = PayrollCfdi::where('payroll_entry_id', $entry->id)->first();
        $this->assertSame(PayrollCfdi::STATUS_ERROR, $cfdi->status);
        $this->assertStringContainsString('RFC inválido', $cfdi->pac_response);
    }

    public function test_stamp_period_endpoint_queues_jobs_and_skips_invalid(): void
    {
        Queue::fake();
        $ok = $this->formalized();
        $bad = $this->formalized(['curp' => null, 'employee_number' => 'E-BAD', 'zkteco_user_id' => 99887]);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14', 'status' => 'draft',
        ]);
        app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $ok);
        app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $bad);
        $period->update(['status' => 'approved']);

        $this->actingAsAdmin();
        $this->post(route('payroll.cfdi.stamp', $period))->assertRedirect(route('payroll.show', $period));

        Queue::assertPushed(\App\Jobs\StampPayrollCfdi::class, 1);
    }

    public function test_recalculation_locked_while_cfdis_stamped(): void
    {
        [$period, $entry] = $this->weeklyEntry($this->formalized());
        app(PayrollCfdiService::class)->stampEntry($entry->fresh(['employee.department', 'employee.position', 'payrollPeriod']));
        $period->update(['status' => 'review']); // recalculable por status...

        $this->actingAsAdmin();
        $this->post(route('payroll.calculate', $period))
            ->assertSessionHas('error'); // ...pero el candado CFDI lo bloquea

        // Cancelar libera el candado.
        app(PayrollCfdiService::class)->cancelPeriod($period);
        $this->post(route('payroll.calculate', $period))->assertSessionHas('success');
        $this->assertCount(1, app(FakePacDriver::class)->canceled);
    }
}
