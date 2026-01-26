<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds employee synchronization statistics to sync_logs table.
     */
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->integer('employees_imported')->default(0)->after('records_created');
            $table->integer('employees_updated')->default(0)->after('employees_imported');
            $table->integer('employees_marked_inactive')->default(0)->after('employees_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn(['employees_imported', 'employees_updated', 'employees_marked_inactive']);
        });
    }
};
