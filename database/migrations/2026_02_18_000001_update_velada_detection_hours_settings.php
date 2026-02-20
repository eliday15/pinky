<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix velada detection window from 0-6 to 22-5.
 *
 * The client requires velada detection to start at 22:00 (10 PM)
 * and end at 5:00 (5 AM), not 0:00-6:00 as originally seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'velada_detection_start_hour')
            ->update([
                'value' => '22',
                'description' => 'Hora (0-23) a partir de la cual se considera velada (trabajo nocturno)',
            ]);

        DB::table('system_settings')
            ->where('key', 'velada_detection_end_hour')
            ->update([
                'value' => '5',
            ]);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'velada_detection_start_hour')
            ->update([
                'value' => '0',
                'description' => 'Hora (0-23) a partir de la cual se considera velada (trabajo nocturno despues de medianoche)',
            ]);

        DB::table('system_settings')
            ->where('key', 'velada_detection_end_hour')
            ->update([
                'value' => '6',
            ]);
    }
};
