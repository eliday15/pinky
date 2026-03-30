<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('incident_types', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('code');
            $table->boolean('requires_document')->default(false)->after('requires_approval');
            $table->boolean('affects_attendance')->default(false)->after('requires_document');
            $table->boolean('has_time_range')->default(false)->after('affects_attendance');
            $table->integer('priority')->default(0)->after('has_time_range');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_types', function (Blueprint $table) {
            $table->dropColumn(['description', 'requires_document', 'affects_attendance', 'has_time_range', 'priority']);
        });
    }
};
