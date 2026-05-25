<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen authorizations.hours from decimal(5,2) to decimal(8,2).
 *
 * The `hours` column is overloaded by compensation application mode: per_hour
 * stores hours (<=24), but one_time stores a unit quantity (e.g. production
 * pieces) and per_day stores a day count. decimal(5,2) capped those at 999.99,
 * which is too small for unit quantities. Widening to decimal(8,2) allows up to
 * 999999.99 without affecting existing values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->decimal('hours', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->decimal('hours', 5, 2)->nullable()->change();
        });
    }
};
