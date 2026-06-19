<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\LateAccumulation;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\LateAbsenceService;
use App\Services\PayrollCalculatorService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Regla mensual retardos→falta (DECISIONES_NEGOCIO_2026-06-04.md §1).
 *
 * Los retardos se acumulan por mes calendario; al cierre se genera UNA
 * incidencia FRT auto-aprobada con floor(retardos/umbral) días, fechada el
 * día 1 del mes siguiente, y se cobra en la primera nómina base calculada
 * después del cierre. Idempotente por (empleado, mes); una FRT soft-deleted
 * no se regenera; los meses previos al corte nunca se procesan.
 *
 * Las fechas viajan a 2026-08-10: junio y julio 2026 están cerrados y el
 * corte de la regla (migración) es 2026-06.
 */
class MonthlyLateAbsenceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 8, 10));

        IncidentType::factory()->create([
            'code' => 'FRT',
            'name' => 'Falta por retardos',
            'category' => 'late_accumulation',
            'is_paid' => false,
            'requires_approval' => false,
            'is_active' => true,
        ]);
    }

    private function service(): LateAbsenceService
    {
        return app(LateAbsenceService::class);
    }

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(): Employee
    {
        // EmployeeFactory crea un Schedule L-V por defecto.
        return Employee::factory()->create([
            'status' => 'active',
            'hourly_rate' => 100.00, // sueldo diario = 800
        ]);
    }

    /**
     * 12 retardos en días hábiles (L-V) de junio 2026. Umbral default 6 → 2 faltas.
     */
    private function twelveJuneLates(Employee $employee): void
    {
        $dates = [
            '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05',
            '2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12',
            '2026-06-15', '2026-06-16',
        ];

        foreach ($dates as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }
    }

    public function test_late_count_excludes_non_working_days_holidays_and_absences(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        // Retardo en sábado (no laborable L-V): no cuenta.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-06',
            'status' => 'late',
            'late_minutes' => 20,
        ]);

        // Retardo en festivo: no cuenta.
        Holiday::factory()->create(['date' => '2026-06-18']);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-18',
            'status' => 'late',
            'late_minutes' => 20,
        ]);

        // Día que escaló a falta (absent): ya es falta por sí mismo, no retardo.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-19',
            'status' => 'absent',
            'late_minutes' => 90,
        ]);

        $this->assertSame(12, $this->service()->lateCountForMonth($employee, Carbon::create(2026, 6, 1)));
    }

    public function test_generates_auto_approved_frt_incident_at_month_close(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $generated = $this->service()->ensureMonthlyIncidentsGenerated($employee);

        $this->assertSame(1, $generated, 'solo junio alcanza umbral; julio sin retardos');

        $incident = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->first();

        $this->assertNotNull($incident);
        $this->assertSame(2, (int) $incident->days_count, '12 retardos / umbral 6 = 2 faltas (proporcional)');
        $this->assertSame('2026-07-01', $incident->start_date->toDateString(), 'fechada el día 1 del mes siguiente');
        $this->assertSame('2026-07-01', $incident->end_date->toDateString());
        $this->assertSame('approved', $incident->status, 'auto-aprobada, sin paso de supervisor');
        $this->assertSame('FRT', $incident->incidentType->code);
    }

    public function test_generation_is_idempotent(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->assertSame(1, $this->service()->ensureMonthlyIncidentsGenerated($employee));
        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee), 'segunda pasada no genera nada');
        $this->assertSame(1, Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->count());
    }

    public function test_soft_deleted_frt_is_not_regenerated(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->service()->ensureMonthlyIncidentsGenerated($employee);
        Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->first()->delete();

        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee), 'borrarla fue decisión humana: no se regenera');
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->count());
        $this->assertSame(1, Incident::withTrashed()->where('employee_id', $employee->id)->where('late_month', '2026-06')->count());
    }

    public function test_months_before_rule_start_are_skipped(): void
    {
        SystemSetting::set('monthly_late_absence_start_month', '2026-07');

        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee));
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->whereNotNull('late_month')->count());
    }

    public function test_current_month_is_never_closed(): void
    {
        $employee = $this->employee();

        // 6 retardos en agosto (mes en curso al 2026-08-10).
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-10'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }

        $this->service()->ensureMonthlyIncidentsGenerated($employee);

        $this->assertSame(0, Incident::where('employee_id', $employee->id)->where('late_month', '2026-08')->count());
    }

    public function test_below_threshold_generates_nothing(): void
    {
        $employee = $this->employee();

        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }

        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee));
    }

    public function test_weekly_payroll_charges_frt_once_in_first_period_after_close(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        // Primera nómina de julio: contiene el 1 de julio (fecha de cargo).
        $firstPeriod = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        // El cálculo mismo garantiza la generación (autocurable, sin cron).
        $entry = $this->calculator()->calculateEmployeePayroll($firstPeriod, $employee);

        // 2 faltas × 800 × 7/5 (séptimo día proporcional, horario L-V = 5 días).
        $this->assertEqualsWithDelta(2240.00, (float) $entry->deductions, 0.01, '2 faltas × 800 × 7/5');
        $this->assertSame(2, (int) $entry->late_absences_generated);
        $this->assertSame(2, (int) $entry->days_absent);

        // Segunda nómina de julio: la misma acumulación NO se vuelve a cobrar.
        $secondPeriod = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-14',
        ]);

        $secondEntry = $this->calculator()->calculateEmployeePayroll($secondPeriod, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $secondEntry->deductions, 0.01);
        $this->assertSame(0, (int) $secondEntry->late_absences_generated);
    }

    public function test_recalculation_does_not_double_charge(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        $this->calculator()->calculateEmployeePayroll($period, $employee);
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee); // recálculo

        $this->assertEqualsWithDelta(2240.00, (float) $entry->deductions, 0.01, 'recalcular no duplica el descuento');
        $this->assertSame(1, Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->count());
    }

    public function test_monthly_extras_period_never_deducts_frt(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        // El periodo mensual (extras) se calcula primero y NO debe "comerse"
        // la falta: el cobro pertenece al periodo base.
        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);

        $monthlyEntry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $monthlyEntry->deductions, 0.01, 'los periodos de extras no descuentan');
        $this->assertSame(0, (int) $monthlyEntry->late_absences_generated);
        $this->assertSame(0, (int) $monthlyEntry->days_absent);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        $weeklyEntry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(2240.00, (float) $weeklyEntry->deductions, 0.01, 'el periodo base sigue cobrando la falta');
    }

    public function test_legacy_weekly_accumulation_is_ignored(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        // Resto del sistema semanal legado: ya no participa en nómina ni se marca.
        $legacy = LateAccumulation::create([
            'employee_id' => $employee->id,
            'year' => 2026,
            'week' => 27,
            'late_count' => 12,
            'absence_generated' => false,
        ]);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(2240.00, (float) $entry->deductions, 0.01, 'solo la FRT mensual descuenta, el contador legado no suma');
        $this->assertFalse((bool) $legacy->fresh()->absence_generated, 'la nómina ya no escribe el flag legado');
    }

    public function test_legacy_frt_incident_without_late_month_still_charges(): void
    {
        // Compatibilidad: una FRT del sistema anterior (sin late_month, fechada
        // a mitad de mes, days_count=1) se sigue cobrando en el periodo que
        // contiene su fecha.
        $employee = $this->employee();

        Incident::create([
            'employee_id' => $employee->id,
            'incident_type_id' => IncidentType::where('code', 'FRT')->first()->id,
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-02',
            'days_count' => 1,
            'reason' => 'FRT legada (sistema semanal)',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        // 1 falta × 800 × 7/5 (horario L-V).
        $this->assertEqualsWithDelta(1120.00, (float) $entry->deductions, 0.01);
        $this->assertSame(1, (int) $entry->late_absences_generated);
    }

    public function test_close_command_generates_incidents_for_closed_month(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->artisan('late-absences:close', ['--month' => '2026-06'])
            ->assertSuccessful();

        $incident = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->first();
        $this->assertNotNull($incident);
        $this->assertSame(2, (int) $incident->days_count);

        // Reejecutar el comando es seguro (idempotente).
        $this->artisan('late-absences:close', ['--month' => '2026-06'])->assertSuccessful();
        $this->assertSame(1, Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->count());
    }

    public function test_close_command_refuses_open_month(): void
    {
        $this->artisan('late-absences:close', ['--month' => '2026-08'])
            ->expectsOutputToContain('aún no termina')
            ->assertSuccessful();
    }
}
