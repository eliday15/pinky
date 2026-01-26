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
        Schema::table('attendance_records', function (Blueprint $table) {
            // Horas cubiertas por permiso (tiempo del permiso que cuenta como trabajado)
            $table->decimal('permission_hours', 5, 2)->default(0)->after('overtime_hours');

            // Horas totales para nómina (worked_hours + permission_hours)
            $table->decimal('total_payroll_hours', 5, 2)->default(0)->after('permission_hours');

            // Califica para bono de puntualidad (llegó 10 min antes)
            $table->boolean('qualifies_for_punctuality_bonus')->default(false)->after('is_weekend_work');

            // ID de autorización vinculada (si aplica)
            $table->foreignId('authorization_id')->nullable()->after('notes')->constrained('authorizations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['authorization_id']);
            $table->dropColumn(['permission_hours', 'total_payroll_hours', 'qualifies_for_punctuality_bonus', 'authorization_id']);
        });
    }
};
