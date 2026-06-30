<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill is_weekend_work para departamentos que pagan el fin de semana por
     * unidades (Almacén PT, los únicos con weekend_unit_hours).
     *
     * La regla nueva (Dani 2026-06-25: "aunque se presenten 1 hora es un fin de
     * semana") es que en esos departamentos CUALQUIER sábado/domingo trabajado es
     * fin de semana, sin importar el horario asignado. Antes is_weekend_work solo
     * marcaba los días FUERA del horario, así que un sábado de un horario L-S (o el
     * domingo de un horario mal configurado, caso Arturo) quedaba en false y nunca
     * aparecía en "Cargar desde checadas".
     *
     * El sync nuevo ya lo calcula bien hacia adelante (Employee::isWeekendWorkDay),
     * pero los registros viejos fuera de la ventana de re-sync (--days=7) conservan
     * el valor anterior. Este backfill los corrige. Idempotente: solo pasa
     * false→true en filas de sábado/domingo; correrlo de nuevo no hace nada. No
     * toca el pago (la nómina paga por la autorización FIN aprobada, no por esta
     * bandera).
     */
    public function up(): void
    {
        $deptIds = DB::table('departments')->whereNotNull('weekend_unit_hours')->pluck('id');
        if ($deptIds->isEmpty()) {
            return;
        }

        $employeeIds = DB::table('employees')->whereIn('department_id', $deptIds)->pluck('id');
        if ($employeeIds->isEmpty()) {
            return;
        }

        // Reunir primero TODAS las filas candidatas (id + fecha) y luego actualizar
        // por id en lotes: no se puede usar chunk() sobre is_weekend_work=false y a
        // la vez ponerlo en true dentro del bucle (la paginación por offset se
        // recorrería y saltaría filas). El filtrado de sábado/domingo se hace en PHP
        // para ser portable entre SQLite (tests) y MySQL (prod), donde DAYOFWEEK
        // difiere.
        $weekendIds = DB::table('attendance_records')
            ->whereIn('employee_id', $employeeIds)
            ->where('is_weekend_work', false)
            ->select('id', 'work_date')
            ->get()
            ->filter(fn ($r) => Carbon::parse($r->work_date)->isWeekend())
            ->pluck('id');

        foreach ($weekendIds->chunk(500) as $idChunk) {
            DB::table('attendance_records')
                ->whereIn('id', $idChunk->all())
                ->update(['is_weekend_work' => true]);
        }
    }

    public function down(): void
    {
        // Backfill de datos: no se revierte (no se puede distinguir las filas que
        // este backfill cambió de las que ya estaban en true).
    }
};
