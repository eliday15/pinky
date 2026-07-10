<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla escalonada de la cuota PATRONAL de Cesantía en Edad Avanzada y Vejez
 * (reforma de pensiones 2020, transitoria 2023-2030): % patronal según el SBC
 * diario medido en UMA. Editable por año (el DOF publica los % de cada año).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_cyv_brackets')) {
            return;
        }

        Schema::create('fiscal_cyv_brackets', function (Blueprint $table) {
            $table->id();
            // Límite superior del rango en UMA (SBC diario / UMA); el último
            // rango usa un tope alto (99).
            $table->decimal('upper_uma', 8, 4);
            $table->decimal('employer_pct', 8, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_cyv_brackets');
    }
};
