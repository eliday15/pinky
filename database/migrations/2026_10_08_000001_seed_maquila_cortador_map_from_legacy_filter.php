<?php

use App\Models\CompensationType;
use App\Models\SystemSetting;
use App\Services\MaquilaBonusMetricsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada cortador cobra lo suyo (Luis 2026-10-08).
 *
 * Antes había UN nombre por concepto (`maquila_bonus_cortador2:<code>` = CARLOS)
 * y su conteo se le pagaba completo a cada empleado asignado. Ahora cada
 * empleado tiene su propio cortador (`maquila_bonus_cortador_map:<code>`).
 *
 * Esta migración conserva lo que ya se estaba pagando: si el concepto tenía
 * nombre y UN solo empleado asignado, ese empleado queda con ese cortador. Con
 * más de un asignado no se adivina —justo el caso que Luis quiere separar—: se
 * quedan sin cortador y la pantalla lo señala en ámbar hasta que se asignen.
 * El filtro global se limpia para que el conteo del concepto vuelva a ser el
 * total de órdenes con cortador.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (MaquilaBonusMetricsService::cortador2FilteredCodes() as $code) {
            $legacyKey = MaquilaBonusMetricsService::cortador2SettingKey($code);
            $legacyName = trim((string) SystemSetting::get($legacyKey, ''));

            if ($legacyName === '') {
                continue;
            }

            $concept = CompensationType::where('code', $code)->first();

            if ($concept !== null) {
                $employeeIds = DB::table('employee_compensation_type')
                    ->where('compensation_type_id', $concept->id)
                    ->where('is_active', true)
                    ->pluck('employee_id');

                if ($employeeIds->count() === 1) {
                    app(MaquilaBonusMetricsService::class)
                        ->setCortadorForEmployee($code, (int) $employeeIds->first(), $legacyName);
                }
            }

            SystemSetting::set($legacyKey, '');
        }
    }

    public function down(): void
    {
        // El mapa por empleado se conserva: revertirlo borraría la asignación
        // que el admin ya hizo a mano.
    }
};
