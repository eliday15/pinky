<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Excedente de tiempo extra NO respaldado por la checada (Elias 2026-08-05).
     *
     * Cuando una autorización de TE reclama más de lo que la checada demuestra,
     * el sistema la PARTE: la porción respaldada se auto-aprueba y el excedente
     * se crea como una autorización pendiente con esta bandera. Aprobarla es la
     * decisión consciente de pagar tiempo extra "no hecho en el reloj": la
     * nómina la paga POR ENCIMA del tope a lo detectado (que de otro modo la
     * dejaría en cero), y por lo mismo se EXCLUYE de la suma autorizada que se
     * topa contra el timecard, para no contarla dos veces.
     */
    public function up(): void
    {
        if (Schema::hasColumn('authorizations', 'is_unbacked_extra')) {
            return;
        }

        Schema::table('authorizations', function (Blueprint $table) {
            $table->boolean('is_unbacked_extra')->default(false)->after('generated_from_authorization_id');
        });
    }

    public function down(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->dropColumn('is_unbacked_extra');
        });
    }
};
