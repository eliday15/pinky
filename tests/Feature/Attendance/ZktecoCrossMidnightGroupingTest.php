<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

/**
 * La madrugada sólo pertenece al día anterior cuando cierra una huella
 * nocturna sin pareja. Una salida nocturna ya emparejada no puede apropiarse
 * de la entrada del día siguiente.
 */
class ZktecoCrossMidnightGroupingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('attendance');
        Schema::create('attendance', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('device_id')->default(1);
            $table->integer('user_id');
            $table->dateTime('timestamp');
            $table->integer('status')->default(0);
            $table->integer('punch')->default(0);
        });

        $this->travelTo(Carbon::parse('2026-09-26 10:00:00'));
    }

    private function employee(int $zktecoId = 123): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:30',
            'daily_work_hours' => 9,
            'break_minutes' => 60,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        return Employee::factory()->create([
            'zkteco_user_id' => $zktecoId,
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

    private function nightEmployee(int $zktecoId): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '22:00',
            'exit_time' => '06:00',
            'daily_work_hours' => 8,
            'break_minutes' => 0,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        return Employee::factory()->create([
            'zkteco_user_id' => $zktecoId,
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

    private function punch(int $userId, string $timestamp): void
    {
        DB::table('attendance')->insert([
            'device_id' => 1,
            'user_id' => $userId,
            'timestamp' => $timestamp,
            'status' => 0,
            'punch' => 0,
        ]);
    }

    public function test_completed_evening_pair_does_not_steal_next_days_early_entry(): void
    {
        $employee = $this->employee();

        // Caso Norma Reyes Ortiz (EMP-0123). El 08:57 es un reintento del
        // lector: tras deduplicarlo, el 24 tiene un par completo 08:55–21:05.
        foreach ([
            '2026-09-24 08:55:00',
            '2026-09-24 08:57:00',
            '2026-09-24 21:05:00',
            '2026-09-25 05:56:00',
            '2026-09-25 20:03:00',
        ] as $timestamp) {
            $this->punch(123, $timestamp);
        }

        app(ZktecoSyncService::class)->syncAttendance(Carbon::parse('2026-09-24'));

        $day24 = AttendanceRecord::whereBelongsTo($employee)->whereDate('work_date', '2026-09-24')->firstOrFail();
        $day25 = AttendanceRecord::whereBelongsTo($employee)->whereDate('work_date', '2026-09-25')->firstOrFail();

        $this->assertSame('08:55:00', $day24->check_in);
        $this->assertSame('21:05:00', $day24->check_out);
        $this->assertSame('05:56:00', $day25->check_in, 'la entrada del 25 conserva su propio día');
        $this->assertSame('20:03:00', $day25->check_out);
        $this->assertSame(
            ['2026-09-25', '2026-09-25'],
            collect($day25->raw_punches)->pluck('date')->all(),
        );
    }

    public function test_unmatched_night_reentry_still_carries_next_morning_to_velada(): void
    {
        $employee = $this->employee(124);

        // Jornada normal, reentrada a velada y salida en la madrugada. El día
        // anterior tiene tres eventos (uno sin pareja), por lo que 05:02 sí lo
        // completa; las huellas diurnas del 25 permanecen en el 25.
        foreach ([
            '2026-09-24 08:00:00',
            '2026-09-24 17:30:00',
            '2026-09-24 22:00:00',
            '2026-09-25 05:02:00',
            '2026-09-25 08:01:00',
            '2026-09-25 17:31:00',
        ] as $timestamp) {
            $this->punch(124, $timestamp);
        }

        app(ZktecoSyncService::class)->syncAttendance(Carbon::parse('2026-09-24'));

        $day24 = AttendanceRecord::whereBelongsTo($employee)->whereDate('work_date', '2026-09-24')->firstOrFail();
        $day25 = AttendanceRecord::whereBelongsTo($employee)->whereDate('work_date', '2026-09-25')->firstOrFail();

        $this->assertSame('05:02:00', $day24->check_out);
        $this->assertSame('2026-09-25', collect($day24->raw_punches)->last()['date']);
        $this->assertSame('08:01:00', $day25->check_in);
        $this->assertSame('17:31:00', $day25->check_out);
    }

    public function test_pure_night_shift_still_carries_its_next_morning_exit(): void
    {
        $employee = $this->nightEmployee(125);

        $this->punch(125, '2026-09-24 22:00:00');
        $this->punch(125, '2026-09-25 05:31:00');

        app(ZktecoSyncService::class)->syncAttendance(Carbon::parse('2026-09-24'));

        $night = AttendanceRecord::whereBelongsTo($employee)->whereDate('work_date', '2026-09-24')->firstOrFail();

        $this->assertSame('22:00:00', $night->check_in);
        $this->assertSame('05:31:00', $night->check_out);
        $this->assertSame(
            ['2026-09-24', '2026-09-25'],
            collect($night->raw_punches)->pluck('date')->all(),
            'la salida conserva su fecha real aunque pertenezca al turno anterior',
        );
    }
}
