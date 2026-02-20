<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Add velada confirmation window settings.
 *
 * Velada workers must punch in between 00:00 and 01:00 to confirm
 * they are still on site. Without this punch, a velada_missing_confirmation
 * anomaly is generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        SystemSetting::updateOrCreate(
            ['key' => 'velada_confirmation_start_hour'],
            [
                'value' => '0',
                'type' => 'integer',
                'group' => 'attendance',
                'label' => 'Hora Inicio Confirmacion Velada',
                'description' => 'Hora (0-23) inicio de ventana obligatoria de checada post-medianoche para veladas',
            ]
        );

        SystemSetting::updateOrCreate(
            ['key' => 'velada_confirmation_end_hour'],
            [
                'value' => '1',
                'type' => 'integer',
                'group' => 'attendance',
                'label' => 'Hora Fin Confirmacion Velada',
                'description' => 'Hora (0-23) fin de ventana obligatoria de checada post-medianoche para veladas',
            ]
        );
    }

    public function down(): void
    {
        SystemSetting::where('key', 'velada_confirmation_start_hour')->delete();
        SystemSetting::where('key', 'velada_confirmation_end_hour')->delete();
    }
};
