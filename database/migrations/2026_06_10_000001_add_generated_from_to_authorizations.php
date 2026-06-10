<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an auto-generated companion authorization (Cena/Comida) back to the
 * velada/fin de semana whose approval produced it. Lets us reject the companion
 * when the parent is reverted, and keep the backfill idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->foreignId('generated_from_authorization_id')
                ->nullable()
                ->after('attendance_record_id')
                ->constrained('authorizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->dropForeign(['generated_from_authorization_id']);
            $table->dropColumn('generated_from_authorization_id');
        });
    }
};
