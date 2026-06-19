<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill del sueldo diario para empleados existentes.
 *
 * El sueldo diario pasa a ser la fuente de verdad del pago. Los empleados
 * creados con el modelo viejo solo tienen hourly_rate poblado (daily_salary
 * nulo o 0); aquí se les deriva el sueldo diario = hourly_rate × horas de la
 * jornada efectiva (mismo cálculo del accessor daily_salary_computed). Debe
 * correr ANTES de que el primer recálculo de nómina dependa de daily_salary,
 * o el base saldría en $0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Employee::withTrashed()
            ->where(fn ($q) => $q->whereNull('daily_salary')->orWhere('daily_salary', 0))
            ->whereNotNull('hourly_rate')
            ->where('hourly_rate', '>', 0)
            ->chunkById(200, function ($employees) {
                foreach ($employees as $employee) {
                    $jornada = (float) ($employee->getEffectiveSchedule()?->daily_work_hours ?? 8);
                    $daily = round((float) $employee->hourly_rate * $jornada, 2);

                    if ($daily > 0) {
                        $employee->daily_salary = $daily;
                        $employee->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        // Sin reversa: no se puede distinguir un sueldo diario derivado de uno
        // capturado a mano. Es un backfill idempotente y seguro de re-correr.
    }
};
