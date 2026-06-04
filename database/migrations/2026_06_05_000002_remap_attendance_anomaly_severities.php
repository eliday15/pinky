<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data fix: re-map the severity of ALL existing anomalies to the new
 * classification (mirrors AttendanceAnomaly::defaultSeverityFor).
 *
 * Rationale: incomplete punches (checked in but never out, or vice versa) are
 * broken data that affects payroll -> CRITICAL. Unpaid extra time is an
 * operational follow-up, not an emergency -> WARNING. The previous rule had
 * it backwards (unauthorized_velada=critical, missing_checkout=warning).
 *
 * Every value written already exists in the severity ENUM, so no ALTER is
 * needed and the UPDATEs are portable across MariaDB and SQLite. down()
 * re-applies the old mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'attendance_anomalies';

        DB::table($t)->whereIn('anomaly_type', ['missing_checkout', 'missing_checkin'])
            ->update(['severity' => 'critical']);

        DB::table($t)->whereIn('anomaly_type', [
            'unauthorized_velada', 'unauthorized_overtime', 'velada_missing_confirmation',
        ])->update(['severity' => 'warning']);

        DB::table($t)->whereIn('anomaly_type', [
            'excessive_break', 'missing_lunch', 'schedule_deviation', 'duplicate_punches', 'excessive_overtime',
        ])->update(['severity' => 'info']);

        $this->remapDeviationBased($t);
    }

    public function down(): void
    {
        $t = 'attendance_anomalies';

        // Old mapping (as the detector assigned before this change).
        DB::table($t)->where('anomaly_type', 'unauthorized_velada')
            ->update(['severity' => 'critical']);

        DB::table($t)->whereIn('anomaly_type', [
            'missing_checkout', 'missing_checkin', 'unauthorized_overtime', 'velada_missing_confirmation',
        ])->update(['severity' => 'warning']);

        DB::table($t)->whereIn('anomaly_type', [
            'excessive_break', 'missing_lunch', 'schedule_deviation', 'duplicate_punches', 'excessive_overtime',
        ])->update(['severity' => 'info']);

        // The >60-min rule is identical in both mappings.
        $this->remapDeviationBased($t);
    }

    /**
     * late_arrival / early_departure: deviation_minutes > 60 -> warning,
     * otherwise (including null) -> info. Same rule in old and new mappings.
     */
    private function remapDeviationBased(string $table): void
    {
        foreach (['late_arrival', 'early_departure'] as $type) {
            DB::table($table)->where('anomaly_type', $type)
                ->where('deviation_minutes', '>', 60)
                ->update(['severity' => 'warning']);

            DB::table($table)->where('anomaly_type', $type)
                ->where(fn ($q) => $q->where('deviation_minutes', '<=', 60)->orWhereNull('deviation_minutes'))
                ->update(['severity' => 'info']);
        }
    }
};
