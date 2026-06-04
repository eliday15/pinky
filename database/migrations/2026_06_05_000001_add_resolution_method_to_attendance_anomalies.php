<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add resolution_method to attendance_anomalies.
 *
 * Records HOW an anomaly was resolved (justified, false_positive,
 * record_corrected, linked_authorization, linked_incident). A plain nullable
 * string (NOT an enum) so new methods can be added without ALTER on MariaDB
 * and the column stays portable on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_anomalies', function (Blueprint $table) {
            $table->string('resolution_method')->nullable()->after('resolution_notes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_anomalies', function (Blueprint $table) {
            $table->dropColumn('resolution_method');
        });
    }
};
