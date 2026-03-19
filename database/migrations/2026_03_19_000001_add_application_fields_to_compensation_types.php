<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add application_mode, authorization_type, and priority to compensation_types.
 * Add compensation_type_id FK to authorizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->enum('application_mode', ['per_hour', 'per_day', 'one_time'])
                ->default('per_hour')
                ->after('is_active');
            $table->string('authorization_type', 50)
                ->nullable()
                ->after('application_mode');
            $table->integer('priority')
                ->default(0)
                ->after('authorization_type');
        });

        Schema::table('authorizations', function (Blueprint $table) {
            $table->foreignId('compensation_type_id')
                ->nullable()
                ->after('type')
                ->constrained('compensation_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->dropForeign(['compensation_type_id']);
            $table->dropColumn('compensation_type_id');
        });

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn(['application_mode', 'authorization_type', 'priority']);
        });
    }
};
