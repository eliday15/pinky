<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/** Regression for Karen's five exact "Cargar desde checadas" rows. */
class KarenBulkOvertimeTest extends FeatureTestCase
{
    public function test_exact_bulk_suggestions_auto_approve_without_unbacked_splits(): void
    {
        $this->actingAsSupervisor();

        $schedule = Schedule::factory()->create([
            'entry_time' => '08:30',
            'exit_time' => '16:30',
            'daily_work_hours' => 7.5,
            'break_minutes' => 30,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'day_schedules' => [
                'friday' => [
                    'entry_time' => '08:00',
                    'exit_time' => '16:00',
                    'daily_work_hours' => 7.5,
                    'break_minutes' => 30,
                ],
            ],
        ]);
        $employee = Employee::factory()->create([
            'full_name' => 'Karen Itzel Guerrero Villafuerte',
            'schedule_id' => $schedule->id,
        ]);

        $rows = [
            ['2026-09-23', '16:30', '17:36', 1.0],
            ['2026-09-24', '16:30', '18:13', 1.5],
            ['2026-09-25', '16:00', '18:11', 2.0],
            ['2026-09-28', '16:30', '18:15', 1.5],
            ['2026-09-29', '16:30', '18:07', 1.5],
        ];

        foreach ($rows as [$date, $start, $end]) {
            AttendanceRecord::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'check_in' => '08:30:00',
                'check_out' => $end.':00',
            ]);
        }

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_OVERTIME,
            'reason' => 'Horas cargadas desde checadas',
            'entries' => array_map(fn (array $row) => [
                'employee_id' => $employee->id,
                'date' => $row[0],
                'start_time' => $row[1],
                'end_time' => $row[2],
                'hours' => $row[3],
            ], $rows),
        ])->assertRedirect(route('authorizations.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, Authorization::where('employee_id', $employee->id)->count());
        $this->assertSame(5, Authorization::where('employee_id', $employee->id)
            ->where('status', Authorization::STATUS_APPROVED)->count());
        $this->assertSame(0, Authorization::where('employee_id', $employee->id)
            ->where('is_unbacked_extra', true)->count());

        $persisted = Authorization::where('employee_id', $employee->id)
            ->orderBy('date')
            ->get()
            ->map(fn (Authorization $authorization) => [
                $authorization->date->toDateString(),
                $authorization->start_time->format('H:i'),
                $authorization->end_time->format('H:i'),
                (float) $authorization->hours,
            ])
            ->all();

        $this->assertSame($rows, $persisted, 'cada fila conserva la ventana exacta cargada desde checadas');
    }
}
