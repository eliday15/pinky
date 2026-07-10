<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Autorización de omisión de checada" (Dani 2026-07-09).
     *
     * Cuando un colaborador no registra su entrada o su salida el sistema genera
     * una falta automática. El jefe/supervisor del departamento puede AUTORIZAR
     * la omisión con un motivo, y el administrador la APRUEBA (flujo de 2 pasos).
     *
     * Motivos:
     *   - "entrega_mercancia" → al aprobarse NO se aplica la falta y el día se
     *     paga completo (día trabajado normal).
     *   - "otro"             → al aprobarse el día se convierte en un RETARDO, que
     *     sí cuenta para el acumulado mensual de retardos → falta (umbral).
     *
     * El reporte se arma con: quién autorizó, quién aprobó, fecha/hora, motivo y
     * comentarios.
     */
    public function up(): void
    {
        Schema::create('check_omissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');

            // Motivo de la omisión: entrega_mercancia | otro
            $table->string('reason');
            // Comentarios / "especificar" del motivo "otro".
            $table->text('comments')->nullable();

            // authorized: el jefe autorizó, falta que el admin apruebe.
            // approved: el admin aprobó (efecto aplicado). rejected: rechazado.
            $table->string('status')->default('authorized');

            // Paso 1: jefe/supervisor del depto AUTORIZA.
            $table->foreignId('authorized_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();

            // Paso 2: administrador APRUEBA.
            $table->foreignId('approved_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Rechazo (admin).
            $table->foreignId('rejected_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Quién capturó el registro (auditoría).
            $table->foreignId('created_by')->nullable()->constrained('app_users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'work_date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_omissions');
    }
};
