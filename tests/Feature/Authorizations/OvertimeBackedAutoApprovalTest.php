<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * Auto-aprobación de TIEMPO EXTRA respaldado por la checada (Luis 2026-07-30)
 * y PARTICIÓN del que reclama de más (Elias 2026-08-05).
 *
 * Antes solo se autoaprobaba el TE que coincidía EXACTO con el segmento
 * detectado (fila cargada desde checadas sin tocar). Las post-autorizaciones
 * tecleadas en números redondos (16:30–19:00 = 2.5h) no cuadraban con la salida
 * real (19:06) y quedaban pendientes. Ahora se autoaprueban cuando la checada
 * RESPALDA la ventana (fin ≤ salida real, horas ≤ detectadas). Lo que reclama
 * MÁS de lo que la checada demuestra se PARTE: la porción respaldada se aprueba
 * sola y el excedente queda pendiente marcado `is_unbacked_extra` — aprobarlo
 * es la decisión consciente de pagar extra no hecho en el reloj.
 */
class OvertimeBackedAutoApprovalTest extends FeatureTestCase
{
    /** Empleado con jornada 08:00–16:30 (como el caso real de Corte: "sale a las 4:30"). */
    private function corteEmployee(): Employee
    {
        return Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '16:30',
            ])->id,
        ]);
    }

    /** Registro que replica el caso real: entra 08:00, sale tarde → TE late 16:30→salida. */
    private function recordWithCheckout(Employee $emp, string $checkOut, float $overtimeHours): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08', // lunes
            'check_in' => '08:00:00',
            'check_out' => $checkOut,
            'overtime_hours' => $overtimeHours,
        ]);
    }

    public function test_backed_overtime_auto_approves_when_captured_by_non_approver(): void
    {
        // Supervisor (captura, NO aprueba) → cae a la auto-aprobación por checadas.
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 19:06 (jornada 08:00–16:30) → detectado 16:30–19:06 = 2.5h.
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Capturado a mano en redondo: 16:30–19:00 (2.5h). NO es match exacto
        // (fin 19:00 ≠ 19:06) pero la checada lo respalda.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'reason' => 'AUTORIZADO POR EDUARDO',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_overtime_claiming_more_than_backed_splits_into_approved_and_flagged_excess(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 19:06 → respalda 16:30–19:06 (2.5h).
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Reclama 16:30–20:00 (3.5h): el fin 20:00 excede la salida real →
        // se parte: 2.5h aprobadas (lo que sí hizo) + 1.0h pendiente marcada
        // como extra fuera de checada.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '20:00',
            'hours' => 3.5,
            'reason' => 'reclama de más',
        ])->assertRedirect(route('authorizations.index'));

        $approved = Authorization::where('employee_id', $emp->id)
            ->where('status', Authorization::STATUS_APPROVED)
            ->first();
        $this->assertNotNull($approved, 'la porción respaldada se aprueba sola');
        $this->assertEqualsWithDelta(2.5, (float) $approved->hours, 0.01);
        $this->assertFalse((bool) $approved->is_unbacked_extra);
        $this->assertSame('19:06', $approved->end_time->format('H:i'), 'encogida a la salida real');

        $excess = Authorization::where('employee_id', $emp->id)
            ->where('status', Authorization::STATUS_PENDING)
            ->first();
        $this->assertNotNull($excess, 'el excedente queda pendiente');
        $this->assertTrue((bool) $excess->is_unbacked_extra, 'marcado como extra fuera de checada');
        $this->assertEqualsWithDelta(1.0, (float) $excess->hours, 0.01);
        $this->assertSame('19:06', $excess->start_time->format('H:i'));
        $this->assertSame('20:00', $excess->end_time->format('H:i'));
        $this->assertSame($approved->id, $excess->generated_from_authorization_id, 'ligado a la porción aprobada');
    }

    public function test_fully_unbacked_overtime_stays_pending_without_split(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 16:40 → solo 10 min tras la jornada → redondea a 0: nada respaldado.
        $this->recordWithCheckout($emp, '16:40:00', 0.0);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'reason' => 'nada en el reloj',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'sin split: una sola autorización');
        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'status' => Authorization::STATUS_PENDING,
            'is_unbacked_extra' => false,
        ]);
    }

    public function test_command_auto_approves_backed_pending_overtime(): void
    {
        $this->adminUser(); // el barrido firma con un admin del sistema
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_APPROVED, $auth->fresh()->status);
    }

    public function test_command_dry_run_does_not_approve(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime --dry-run')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PENDING, $auth->fresh()->status, 'dry-run no aprueba');
    }

    public function test_command_splits_overclaimed_pending_overtime(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        // Salida 17:30 → solo 1.0h respaldada (16:30–17:30).
        $this->recordWithCheckout($emp, '17:30:00', 1.0);

        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5, // reclama 2.5 pero la checada solo respalda 1.0
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        // El barrido la parte: 1.0h aprobada (encogida a 17:30) + 1.5h
        // pendiente marcada como extra fuera de checada.
        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEqualsWithDelta(1.0, (float) $auth->hours, 0.01);
        $this->assertSame('17:30', $auth->end_time->format('H:i'));

        $excess = Authorization::where('generated_from_authorization_id', $auth->id)->first();
        $this->assertNotNull($excess);
        $this->assertTrue((bool) $excess->is_unbacked_extra);
        $this->assertSame(Authorization::STATUS_PENDING, $excess->status);
        $this->assertEqualsWithDelta(1.5, (float) $excess->hours, 0.01);
    }

    public function test_command_never_touches_flagged_excess(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Un excedente ya partido (dentro de la ventana detectada, para probar
        // que la bandera manda): el barrido NO lo aprueba ni lo vuelve a partir.
        $excess = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
            'is_unbacked_extra' => true,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PENDING, $excess->fresh()->status, 'el excedente espera decisión humana');
        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'no se re-parte');
    }

    public function test_pm_window_typed_as_am_normalizes_and_approves(): void
    {
        // Caso Karla #4158 (Luis 2026-08-06): capturaron "05:30–07:00" en
        // formato de 12 horas cuando el extra real fue 17:30–19:00 (horario
        // 08:00–17:30, salida 19:06). La ventana tal cual no toca nada en la
        // madrugada → se reinterpreta +12 h, se aprueba y los tiempos
        // guardados quedan normalizados a la tarde.
        $this->adminUser();
        $emp = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-04', // martes
            'check_in' => '08:10:00',
            'check_out' => '19:06:00',
            'overtime_hours' => 1.6,
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-08-04',
            'start_time' => '05:30',
            'end_time' => '07:00',
            'hours' => 1.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertSame('17:30', $auth->start_time->format('H:i'), 'normalizada a la ventana real P.M.');
        $this->assertSame('19:00', $auth->end_time->format('H:i'));
        $this->assertEqualsWithDelta(1.5, (float) $auth->hours, 0.01);
        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'sin excedente: las horas caben completas');
    }

    public function test_genuine_early_morning_claim_is_not_shifted(): void
    {
        // Una madrugada real (entrada 05:08 con horario de las 09:00) SÍ
        // traslapa su segmento detectado tal cual → nunca se reinterpreta
        // como P.M.; se aprueba con sus tiempos originales.
        $this->adminUser();
        $emp = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '09:00',
                'exit_time' => '18:00',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-04',
            'check_in' => '05:08:00',
            'check_out' => '18:05:00',
            'overtime_hours' => 3.9,
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-08-04',
            'start_time' => '05:10',
            'end_time' => '09:00',
            'hours' => 4.0,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertSame('05:10', $auth->start_time->format('H:i'), 'la madrugada real conserva sus tiempos');
    }

    public function test_overtime_backed_by_exit_after_midnight(): void
    {
        // Caso Elizabeth #4042 (Luis 2026-08-06): velada sobre horario de día
        // — entrada 08:07, salida 01:00 del día siguiente. El TE de la tarde
        // (17:30–22:00 = 4.5h) sí está respaldado por la salida real; antes el
        // segmento nunca se emitía (la 01:00 se leía como anterior al horario)
        // y quedaba pendiente.
        $this->adminUser();
        $emp = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-07-29', // miércoles
            'check_in' => '08:07:00',
            'check_out' => '01:00:00', // cruza medianoche
            'overtime_hours' => 4.5,
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-07-29',
            'start_time' => '17:30',
            'end_time' => '22:00',
            'hours' => 4.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status, 'la salida de la 01:00 respalda el TE de la tarde');
        $this->assertSame('17:30', $auth->start_time->format('H:i'));
        $this->assertSame('22:00', $auth->end_time->format('H:i'));
    }

    public function test_recalcular_button_approves_backed_pending(): void
    {
        // Botón "Recalcular pendientes" (Luis 2026-08-06): mismo motor que el
        // barrido, disparado desde la pantalla de autorizaciones.
        $this->actingAs($this->adminUser());
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->post(route('authorizations.autoApprovePending'))
            ->assertRedirect(route('authorizations.index'));

        $this->assertSame(Authorization::STATUS_APPROVED, $auth->fresh()->status);
    }

    public function test_recalcular_button_requires_approve_permission(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('authorizations.autoApprovePending'))->assertForbidden();
    }

    public function test_weekend_overtime_capture_is_not_blocked_by_schedule_overlap(): void
    {
        // Luis 2026-08-11: "no me deja poner tiempo extra sábado o domingo".
        // Sáb/dom no son obligatorios: trabajarlos ES extra, así que la regla
        // de "horas dentro de la jornada" no aplica en fin de semana aunque la
        // ventana pise el horario entre-semana del empleado.
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee(); // horario 08:00–16:30

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-07', // domingo
            'start_time' => '10:00', // dentro del horario entre-semana
            'end_time' => '14:00',
            'hours' => 4.0,
            'reason' => 'trabajo de domingo',
        ])->assertRedirect(route('authorizations.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-07',
        ]);
    }

    public function test_weekend_overtime_is_backed_by_full_punch_span_not_schedule(): void
    {
        // Caso Miguel (Almacén PT, Luis 2026-08-11): 6h de sábado 16:10–22:00
        // con checada 16:10 → 05:01 (+1). El detector usaba la salida
        // entre-semana (17:30) como frontera y partía 1.5h como "fuera de
        // checada". En fin de semana no hay jornada: TODO el rango checado
        // respalda, así que la captura completa se aprueba sin split.
        $this->adminUser();
        $emp = Employee::factory()->create([
            // Depto por UNIDADES (como Almacén PT): sin umbral de finde, el
            // detector cae al camino de segmentos por horario.
            'department_id' => \App\Models\Department::factory()->create(['weekend_unit_hours' => 6])->id,
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-08', // sábado
            'check_in' => '16:10:00',
            'check_out' => '05:01:00', // cruza medianoche
            'is_weekend_work' => true,
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-08-08',
            'start_time' => '16:10',
            'end_time' => '22:00',
            'hours' => 6.0,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status, 'el rango completo del finde respalda las 6h');
        $this->assertEqualsWithDelta(6.0, (float) $auth->hours, 0.01, 'sin split: no se parte nada');
        $this->assertSame('16:10', $auth->start_time->format('H:i'));
        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'sin excedente creado');
    }

    public function test_backed_velada_auto_approves_without_exact_match(): void
    {
        // Caso Miguel (Almacén PT, Luis 2026-08-11): checadas con bloque
        // nocturno real (re-entrada 01:01, salida 05:01) y velada capturada a
        // mano con 1.0h SIN horario. El concepto VEL jala de asistencia, pero
        // la velada respaldada por el bloque ya no exige match exacto ni queda
        // fuera por el pull-rule.
        $this->adminUser();
        $emp = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-09',
            'check_in' => '16:10:00',
            'check_out' => '05:01:00',
            'raw_punches' => [
                ['time' => '16:10:00', 'type' => 'punch'],
                ['time' => '22:00:00', 'type' => 'punch'],
                ['time' => '01:01:00', 'type' => 'punch'],
                ['time' => '05:01:00', 'type' => 'punch'],
            ],
        ]);
        $vel = \App\Models\CompensationType::factory()->create([
            'code' => 'VEL',
            'application_mode' => \App\Models\CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_NIGHT_SHIFT,
            'attendance_pull_rule' => \App\Models\CompensationType::PULL_RULE_VELADA,
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'compensation_type_id' => $vel->id,
            'date' => '2026-08-09',
            'start_time' => null,
            'end_time' => null,
            'hours' => 1.0,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_APPROVED, $auth->fresh()->status, 'el bloque nocturno real respalda la velada');
    }

    public function test_velada_without_night_block_stays_pending(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee(); // 08:00–16:30
        // Día normal: entrada/salida de día, sin re-entrada nocturna.
        $this->recordWithCheckout($emp, '17:00:00', 0.5);
        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => '2026-06-08',
            'start_time' => null,
            'end_time' => null,
            'hours' => 1.0,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PENDING, $auth->fresh()->status, 'sin bloque nocturno no hay respaldo');
    }

    public function test_morning_overtime_uses_calendar_date_punch_kept_on_previous_velada(): void
    {
        // Caso Policarpo 28/08 (Luis 2026-09-02): la huella 05:00 del viernes
        // queda en el record del jueves porque cierra su velada 22:01–05:00.
        // Esa misma frontera respalda el TE 05:00–08:00 del viernes sin moverla
        // del jueves ni marcar el TE como "fuera de checada".
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee(); // viernes 08:00–16:30

        $previous = AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-27',
            'check_in' => '06:47:04',
            'check_out' => '05:00:16',
            'raw_punches' => [
                ['date' => '2026-08-27', 'time' => '06:47:04', 'type' => 'in'],
                ['date' => '2026-08-27', 'time' => '22:01:42', 'type' => 'punch'],
                ['date' => '2026-08-28', 'time' => '01:01:16', 'type' => 'punch'],
                ['date' => '2026-08-28', 'time' => '05:00:16', 'type' => 'out'],
            ],
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-28',
            'check_in' => '07:58:50',
            'check_out' => '16:30:15',
            'overtime_hours' => 0.02,
            'raw_punches' => [
                ['date' => '2026-08-28', 'time' => '07:58:50', 'type' => 'in'],
                ['date' => '2026-08-28', 'time' => '16:30:15', 'type' => 'out'],
            ],
        ]);

        $this->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-08-28',
            'start_time' => '05:00',
            'end_time' => '08:00',
            'hours' => 3.0,
            'reason' => 'Carga de mercancía',
        ])->assertRedirect(route('authorizations.index'));

        $authorization = Authorization::where('employee_id', $emp->id)
            ->whereDate('date', '2026-08-28')
            ->firstOrFail();
        $this->assertSame(Authorization::STATUS_APPROVED, $authorization->status);
        $this->assertFalse((bool) $authorization->is_unbacked_extra, 'la huella sí la respalda');
        $this->assertEqualsWithDelta(3.0, (float) $authorization->hours, 0.01);
        $this->assertSame('05:00:16', $previous->fresh()->check_out, 'la velada anterior conserva su cierre');
        $this->assertCount(2, AttendanceRecord::where('employee_id', $emp->id)->get(), 'no duplica registros');
    }

    public function test_previous_record_punch_without_calendar_date_does_not_back_morning_overtime(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-27',
            'check_in' => '06:47:04',
            'check_out' => '05:00:16',
            'raw_punches' => [
                ['time' => '22:01:42', 'type' => 'punch'],
                ['time' => '05:00:16', 'type' => 'out'],
            ],
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-28',
            'check_in' => '07:58:50',
            'check_out' => '16:30:15',
            'raw_punches' => [],
        ]);

        $this->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-08-28',
            'start_time' => '05:00',
            'end_time' => '08:00',
            'hours' => 3.0,
            'reason' => 'sin fecha verificable',
        ]);

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'date' => '2026-08-28',
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_previous_day_morning_punch_without_night_crossing_does_not_back_overtime(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-27',
            'check_in' => '08:00:00',
            'check_out' => '17:30:00',
            'raw_punches' => [
                ['date' => '2026-08-27', 'time' => '08:00:00', 'type' => 'in'],
                ['date' => '2026-08-28', 'time' => '05:00:16', 'type' => 'out'],
            ],
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-08-28',
            'check_in' => '07:58:50',
            'check_out' => '16:30:15',
            'raw_punches' => [],
        ]);

        $this->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-08-28',
            'start_time' => '05:00',
            'end_time' => '08:00',
            'hours' => 3.0,
            'reason' => 'sin cruce nocturno',
        ]);

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'date' => '2026-08-28',
            'status' => Authorization::STATUS_PENDING,
        ]);
    }
}
