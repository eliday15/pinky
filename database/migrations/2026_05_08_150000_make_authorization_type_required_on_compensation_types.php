<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill any NULL authorization_type as 'special' and enforce NOT NULL
 * so every compensation type can be authorized.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('compensation_types')
            ->whereNull('authorization_type')
            ->update(['authorization_type' => 'special']);

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->string('authorization_type', 50)
                ->default('special')
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->string('authorization_type', 50)
                ->nullable()
                ->default(null)
                ->change();
        });
    }
};
