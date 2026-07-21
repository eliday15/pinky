<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobadores nombrados por concepto de compensación (2026-07-21).
 *
 * Sin filas para un concepto = SIN restricción: aprueba quien ya podía
 * (admin/rrhh/supervisor de su equipo), exactamente como hasta hoy. Esto
 * mantiene intactos los conceptos que ya existen.
 *
 * Con filas = candado: sólo esos usuarios (más el superadmin, que nunca se
 * queda fuera) pueden aprobar o rechazar una autorización de ese concepto.
 * Estar en la lista OTORGA la facultad para ESE concepto, aunque el usuario no
 * tenga el permiso general `authorizations.approve`.
 *
 * Sólo el superadmin edita esta lista (ver CompensationTypeController).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compensation_type_approver')) {
            return;
        }

        Schema::create('compensation_type_approver', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compensation_type_id')
                ->constrained('compensation_types')
                ->cascadeOnDelete();
            // Los usuarios de Laravel viven en `app_users` (no en `users`, que
            // es la tabla de los dispositivos ZKTeco).
            $table->foreignId('user_id')
                ->constrained('app_users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['compensation_type_id', 'user_id'], 'comp_type_approver_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_type_approver');
    }
};
