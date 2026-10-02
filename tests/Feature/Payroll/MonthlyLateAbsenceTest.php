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
 * Regla mensual retardos→falta (DECISIONES §1, ACTUALIZADA por Luis
 * 2026-10-01: la falta se aplica en el corte donde se cumple el umbral).
 *
 * Los retardos se acumulan por mes calendario; cada cruce del umbral genera
 * DE INMEDIATO una incidencia FRT auto-aprobada de 1 día fechada el día del
 * retardo que cruzó (6º → una, 12º → otra con secuencia '#2'), y se cobra en
 * el periodo base que contiene esa fecha. Idempotente por (empleado, mes,
 * secuencia); una FRT soft-deleted no se regenera (perdón); los meses previos
 * al corte nunca se procesan.
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

    public function test_exempt_employee_never_generates_frt(): void
    {
        // "No checa": los retardos residuales (de antes de marcar la casilla)
        // jamás generan falta por acumulación (Elias 2026-08-07).
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $employee->update(['is_attendance_exempt' => true]);

        $incidents = $this->service()->generateForMonth(
            $employee->fresh(),
            \Carbon\Carbon::parse('2026-06-01'),
            \Carbon\Carbon::parse('2026-07-05'),
        );

        $this->assertSame([], $incidents, 'exento de checador: sin FRT aunque tenga retardos residuales');
    }

    public function test_generates_one_auto_approved_frt_per_threshold_crossing(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $generated = $this->service()->ensureMonthlyIncidentsGenerated($employee);

        $this->assertSame(2, $generated, '12 retardos / umbral 6 = 2 faltas, una por cruce');

        $first = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->first();
        $second = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06#2')->first();

        $this->assertNotNull($first);
        $this->assertSame(1, (int) $first->days_count);
        $this->assertSame('2026-06-08', $first->start_date->toDateString(), 'fechada el día del 6º retardo: cae en ese corte');
        $this->assertSame('approved', $first->status, 'auto-aprobada, sin paso de supervisor');
        $this->assertSame('FRT', $first->incidentType->code);

        $this->assertNotNull($second);
        $this->assertSame(1, (int) $second->days_count);
        $this->assertSame('2026-06-16', $second->start_date->toDateString(), 'la segunda, el día del 12º retardo');
    }

    public function test_generation_is_idempotent(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->assertSame(2, $this->service()->ensureMonthlyIncidentsGenerated($employee));
        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee), 'segunda pasada no genera nada');
        $this->assertSame(2, Incident::where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->count());
    }

    public function test_soft_deleted_frt_is_not_regenerated(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->service()->ensureMonthlyIncidentsGenerated($employee);
        Incident::where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->get()->each->delete();

        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee), 'borrarlas fue un perdón humano: no se regeneran');
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->count());
        $this->assertSame(2, Incident::withTrashed()->where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->count());
    }

    public function test_months_before_rule_start_are_skipped(): void
    {
        SystemSetting::set('monthly_late_absence_start_month', '2026-07');

        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee));
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->whereNotNull('late_month')->count());
    }

    public function test_current_month_generates_immediately_on_sixth_late(): void
    {
        // Volteado a propósito (Luis 2026-10-01): antes el mes en curso jamás
        // generaba; ahora el 6º retardo del mes produce la falta ese mismo
        // día, para que se descuente en el corte donde se cumplió la regla.
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

        $incident = Incident::where('employee_id', $employee->id)->where('late_month', '2026-08')->first();
        $this->assertNotNull($incident, 'el mes en curso genera al cruzar el umbral');
        $this->assertSame('2026-08-10', $incident->start_date->toDateString(), 'fechada el día del 6º retardo');
        $this->assertSame(1, (int) $incident->days_count);
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

    public function test_weekly_payroll_charges_each_frt_in_the_period_that_contains_its_crossing(): void
    {
        // Regla de Luis 2026-10-01: cada falta se cobra en el CORTE donde se
        // cumplió su umbral (6º retardo = 8 jun, 12º = 16 jun) — ya no en la
        // primera nómina del mes siguiente.
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        // Semana que contiene el 6º retardo (8 jun). El cálculo mismo
        // garantiza la generación (autocurable, sin cron).
        $firstPeriod = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($firstPeriod, $employee);

        // 1 falta × 800 × 7/6 (séptimo día, divisor fijo 6 para todos).
        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, 'la 1ª falta se cobra en su corte');
        $this->assertSame(1, (int) $entry->late_absences_generated);
        $this->assertSame(1, (int) $entry->days_absent);

        // Semana del 12º retardo (16 jun): cobra la segunda.
        $secondPeriod = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-21',
        ]);

        $secondEntry = $this->calculator()->calculateEmployeePayroll($secondPeriod, $employee);

        $this->assertEqualsWithDelta(933.33, (float) $secondEntry->deductions, 0.01, 'la 2ª falta se cobra en el corte del 12º retardo');
        $this->assertSame(1, (int) $secondEntry->late_absences_generated);

        // La primera nómina de julio ya no cobra nada de junio.
        $julyPeriod = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        $julyEntry = $this->calculator()->calculateEmployeePayroll($julyPeriod, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $julyEntry->deductions, 0.01, 'julio no arrastra las faltas de junio');
        $this->assertSame(0, (int) $julyEntry->late_absences_generated);
    }

    public function test_recalculation_does_not_double_charge(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
        ]);

        $this->calculator()->calculateEmployeePayroll($period, $employee);
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee); // recálculo

        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, 'recalcular no duplica el descuento');
        $this->assertSame(2, Incident::where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->count());
    }

    public function test_monthly_extras_period_never_deducts_frt(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        // El periodo mensual (extras) se calcula primero y NO debe "comerse"
        // la falta: el cobro pertenece al periodo base.
        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $monthlyEntry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $monthlyEntry->deductions, 0.01, 'los periodos de extras no descuentan');
        $this->assertSame(0, (int) $monthlyEntry->late_absences_generated);
        $this->assertSame(0, (int) $monthlyEntry->days_absent);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
        ]);

        $weeklyEntry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(933.33, (float) $weeklyEntry->deductions, 0.01, 'el periodo base sigue cobrando la falta de su corte');
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
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, 'solo la FRT mensual descuenta, el contador legado no suma');
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

        // 1 falta × 800 × 7/6 (divisor fijo 6).
        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01);
        $this->assertSame(1, (int) $entry->late_absences_generated);
    }

    public function test_close_command_generates_incidents_for_closed_month(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->artisan('late-absences:close', ['--month' => '2026-06'])
            ->assertSuccessful();

        $incidents = Incident::where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->orderBy('start_date')->get();
        $this->assertCount(2, $incidents, 'una incidencia por cruce de umbral');
        $this->assertSame('2026-06-08', $incidents[0]->start_date->toDateString());
        $this->assertSame('2026-06-16', $incidents[1]->start_date->toDateString());

        // Reejecutar el comando es seguro (idempotente).
        $this->artisan('late-absences:close', ['--month' => '2026-06'])->assertSuccessful();
        $this->assertSame(2, Incident::where('employee_id', $employee->id)->where('late_month', 'like', '2026-06%')->count());
    }

    public function test_close_command_processes_current_month_and_refuses_future(): void
    {
        // Volteado a propósito (Luis 2026-10-01): el mes en curso SÍ se
        // procesa (genera al cruce del umbral); solo los futuros se rechazan.
        $employee = $this->employee();
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-10'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }

        $this->artisan('late-absences:close', ['--month' => '2026-08'])->assertSuccessful();
        $this->assertSame(1, Incident::where('employee_id', $employee->id)->where('late_month', '2026-08')->count());

        $this->artisan('late-absences:close', ['--month' => '2026-09'])
            ->expectsOutputToContain('es futuro')
            ->assertSuccessful();
    }

    public function test_transition_old_rule_frt_counts_as_generated(): void
    {
        // Transición: septiembre 2026 se procesó con la regla vieja (UNA
        // incidencia días=N fechada el día 1 del mes siguiente). El generador
        // nuevo suma esos días y no duplica.
        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        Incident::create([
            'employee_id' => $employee->id,
            'incident_type_id' => IncidentType::where('code', 'FRT')->first()->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-01',
            'days_count' => 2,
            'late_month' => '2026-06',
            'reason' => 'FRT regla vieja (cierre de mes)',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee), 'los 2 días ya generados cubren los 2 cruces');
    }

    public function test_admin_can_delete_approved_frt_as_a_pardon(): void
    {
        // El perdón de la falta por retardos es autoservible: Admin/RRHH la
        // borra desde la UI y el servicio jamás la regenera.
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->service()->ensureMonthlyIncidentsGenerated($employee);
        $incident = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->first();

        $this->actingAsAdmin();
        $this->delete(route('incidents.destroy', $incident))->assertRedirect();

        $this->assertNotNull($incident->fresh()->deleted_at, 'la FRT aprobada se puede borrar (perdón)');
        $this->assertSame(0, $this->service()->ensureMonthlyIncidentsGenerated($employee), 'y no se regenera');
    }

    public function test_breakdown_details_which_late_days_caused_the_absence(): void
    {
        // El recibo debe explicar la falta por retardos: de qué mes y CUÁLES
        // retardos (fechas) la originaron, no solo "por acumulación de retardos".
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->service()->ensureMonthlyIncidentsGenerated($employee);

        // Periodo semanal que contiene el 8 jun (6º retardo), donde cae la
        // primera FRT de junio con la regla nueva.
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        $breakdown = $entry->calculation_breakdown;

        $detail = $breakdown['late_accumulation']['detail'] ?? [];
        $this->assertNotEmpty($detail, 'el desglose incluye el detalle de la FRT');
        $this->assertSame('2026-06', $detail[0]['month']);
        $this->assertCount(12, $detail[0]['late_dates'], 'lista los 12 retardos de junio');
        $this->assertContains('2026-06-01', $detail[0]['late_dates']);

        // También en el renglón de deducción por fecha nula (la acumulación).
        $frtRow = collect($breakdown['deduction_detail'])->firstWhere('reason', 'Falta por acumulación de retardos');
        $this->assertNotNull($frtRow);
        $this->assertSame('2026-06', $frtRow['late_detail'][0]['month']);
    }

    public function test_batch_handles_first_active_month_and_ignores_future_lates(): void
    {
        SystemSetting::set('monthly_late_absence_start_month', '2026-06');
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->travelTo(Carbon::parse('2026-06-08'));
        $this->assertSame(1, $this->service()->ensureForEmployees(collect([$employee])));
        $this->assertSame(1, Incident::where('employee_id', $employee->id)->count());
        $this->travelTo(Carbon::parse('2026-06-16'));
        $this->assertSame(1, $this->service()->ensureForEmployees(collect([$employee])));
        $this->assertSame(0, $this->service()->ensureForEmployees(collect([$employee])));
    }

    public function test_batch_reconciles_late_import_after_month_closed(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        AttendanceRecord::where('employee_id', $employee->id)->where('work_date', '>', '2026-06-08')->update(['status' => 'present']);
        $this->assertSame(1, $this->service()->ensureForEmployees(collect([$employee])));
        AttendanceRecord::where('employee_id', $employee->id)->update(['status' => 'late']);
        $this->assertSame(1, $this->service()->ensureForEmployees(collect([$employee])));
        $this->assertSame(2, Incident::where('employee_id', $employee->id)->approved()->count());
    }

    public function test_corrected_source_revokes_then_restores_without_new_incident(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->service()->ensureForEmployees(collect([$employee]));
        AttendanceRecord::where('employee_id', $employee->id)->where('work_date', '2026-06-01')->update(['status' => 'present']);
        $this->service()->ensureForEmployees(collect([$employee]));
        $first = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06')->first();
        $second = Incident::where('employee_id', $employee->id)->where('late_month', '2026-06#2')->first();
        $this->assertSame('2026-06-09', $first->start_date->toDateString());
        $this->assertSame('rejected', $second->status);
        $this->assertStringStartsWith('Corrección automática', $second->rejection_reason);
        AttendanceRecord::where('employee_id', $employee->id)->where('work_date', '2026-06-01')->update(['status' => 'late']);
        $this->service()->ensureForEmployees(collect([$employee]));
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame(2, Incident::withTrashed()->where('employee_id', $employee->id)->count());
    }

    public function test_corrected_source_flags_paid_receipt_without_rewriting_amount(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->service()->ensureForEmployees(collect([$employee]));
        $period = PayrollPeriod::factory()->weekly()->create(['start_date' => '2026-06-08', 'end_date' => '2026-06-14']);
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        $deductions = $entry->deductions;
        $period->update(['status' => 'paid']);
        AttendanceRecord::where('employee_id', $employee->id)->update(['status' => 'present']);
        $this->service()->ensureForEmployees(collect([$employee]));
        $this->assertSame($deductions, $entry->fresh()->deductions);
        $this->assertTrue($period->fresh()->requires_recalculation);
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->approved()->count());
    }

    public function test_monthly_close_retains_previous_month_default_and_current_option(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->travelTo(Carbon::parse('2026-07-01'));
        $this->artisan('late-absences:close')->assertSuccessful();
        $this->assertSame(2, Incident::where('employee_id', $employee->id)->count());
        $this->artisan('late-absences:close', ['--current' => true])->assertSuccessful();
        $this->assertSame(2, Incident::where('employee_id', $employee->id)->count());
        $this->artisan('late-absences:close', ['--month' => '2026-13'])->assertFailed();
    }

    public function test_exemption_reconciles_existing_auto_incidents_using_fresh_employee(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->service()->ensureForEmployees(collect([$employee]));
        Employee::whereKey($employee->id)->update(['is_attendance_exempt' => true]);
        $this->service()->ensureForEmployees(collect([$employee]));
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->approved()->count());
    }

    public function test_human_rejection_and_pardon_survive_source_reconciliation(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $this->service()->ensureForEmployees(collect([$employee]));
        $incidents = Incident::where('employee_id', $employee->id)->orderBy('id')->get();
        $incidents[0]->update(['status' => 'rejected', 'rejection_reason' => 'Perdón autorizado por RRHH']);
        $incidents[1]->delete();
        AttendanceRecord::where('employee_id', $employee->id)->update(['status' => 'present']);
        $this->service()->ensureForEmployees(collect([$employee]));
        AttendanceRecord::where('employee_id', $employee->id)->update(['status' => 'late']);
        $this->service()->ensureForEmployees(collect([$employee]));
        $this->assertSame('rejected', $incidents[0]->fresh()->status);
        $this->assertTrue($incidents[1]->fresh()->trashed());
        $this->assertSame(2, Incident::withTrashed()->where('employee_id', $employee->id)->count());
    }

    public function test_close_dry_run_counts_only_existing_lates_for_non_exempt_employees(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        $exempt = $this->employee();
        $this->twelveJuneLates($exempt);
        $exempt->update(['is_attendance_exempt' => true]);
        $this->travelTo(Carbon::parse('2026-06-08'));

        $this->artisan('late-absences:close', ['--current' => true, '--dry-run' => true])
            ->expectsTable(['No. Empleado', 'Empleado', 'Retardos', 'Faltas', 'Resultado'], [
                [$employee->employee_number, $employee->full_name, 6, 1, 'dry-run'],
                [$exempt->employee_number, $exempt->full_name, 0, 0, 'dry-run'],
            ])->assertSuccessful();
        $this->assertSame(0, Incident::whereIn('employee_id', [$employee->id, $exempt->id])->count());
    }
}
