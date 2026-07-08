<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de desayunos: registro de cada desayuno cobrado en el kiosco.
 *
 * Cada fila es un desayuno entregado a un empleado que llegó ANTES de su hora
 * de entrada. unit_cost congela el precio vigente al momento del cobro para
 * que la nómina semanal del vendedor no cambie si el precio se ajusta después.
 * El unique (employee_id, claim_date) garantiza máximo un desayuno por día.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('breakfast_claims')) {
            return;
        }

        Schema::create('breakfast_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('claim_date');
            $table->dateTime('claimed_at');
            $table->decimal('unit_cost', 8, 2);
            $table->decimal('face_match_distance', 5, 4)->nullable();
            $table->string('evidence_photo_path')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'claim_date']);
            $table->index('claim_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breakfast_claims');
    }
};
