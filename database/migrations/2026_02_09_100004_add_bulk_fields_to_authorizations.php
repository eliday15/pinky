<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: Add bulk authorization and department head fields to authorizations.
 *
 * Enables department head sign-off workflow and bulk generation
 * of authorizations grouped by a UUID identifier.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->foreignId('department_head_id')
                ->nullable()
                ->after('approved_by')
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamp('department_head_signed_at')
                ->nullable()
                ->after('department_head_id');
            $table->boolean('is_bulk_generated')->default(false);
            $table->string('bulk_group_id', 36)->nullable();

            // Index for bulk group lookups
            $table->index('bulk_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authorizations', function (Blueprint $table) {
            $table->dropForeign(['department_head_id']);
            $table->dropIndex(['bulk_group_id']);
            $table->dropColumn([
                'department_head_id',
                'department_head_signed_at',
                'is_bulk_generated',
                'bulk_group_id',
            ]);
        });
    }
};
