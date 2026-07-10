<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CFDI de nómina por entry: estado del timbrado ante el PAC (Facturama),
 * UUID fiscal, rutas del XML/PDF y la respuesta cruda del PAC para depurar.
 * Un entry puede tener varios registros a lo largo del tiempo (cancelado →
 * re-timbrado), pero solo uno ACTIVO (stamped/pending) — lo garantiza la capa
 * de servicio, no la BD, para conservar el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_cfdis')) {
            return;
        }

        Schema::create('payroll_cfdis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending|stamped|error|canceled
            $table->uuid('uuid')->nullable();                 // folio fiscal del SAT
            $table->string('pac_id')->nullable();             // id del documento en el PAC
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('pac_response')->nullable();         // último error / respuesta
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('stamped_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['payroll_entry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_cfdis');
    }
};
