<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data fix: close open unauthorized_overtime / unauthorized_velada
 * anomalies that can never be acted on.
 *
 * The authorization standard rounds time with the official company ladder
 * (<30 min → 0h) and the create form blocks 0-hour authorizations. Anomalies
 * whose detected time is under 30 minutes are therefore unactionable noise:
 * no authorization can ever resolve them. The detector now applies the same
 * standard going forward (AnomalyDetectorService); this migration cleans the
 * backlog that was created under the old raw rule.
 *
 * Anomalies are resolved (not deleted) with a marker note so the change is
 * auditable and reversible via down().
 */
return new class extends Migration
{
    private const MARKER = 'Auto-resuelto: tiempo menor a 30 minutos se redondea a 0 horas (estandar de autorizaciones).';

    private const TYPES = ['unauthorized_overtime', 'unauthorized_velada'];

    public function up(): void
    {
        $recordIds = DB::table('attendance_anomalies')
            ->whereIn('anomaly_type', self::TYPES)
            ->where('status', 'open')
            ->whereNotNull('deviation_minutes')
            ->where('deviation_minutes', '<', 30)
            ->distinct()
            ->pluck('attendance_record_id')
            ->filter()
            ->all();

        DB::table('attendance_anomalies')
            ->whereIn('anomaly_type', self::TYPES)
            ->where('status', 'open')
            ->whereNotNull('deviation_minutes')
            ->where('deviation_minutes', '<', 30)
            ->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_notes' => self::MARKER,
            ]);

        $this->recountAnomalies($recordIds);
    }

    public function down(): void
    {
        $recordIds = DB::table('attendance_anomalies')
            ->whereIn('anomaly_type', self::TYPES)
            ->where('status', 'resolved')
            ->where('resolution_notes', self::MARKER)
            ->distinct()
            ->pluck('attendance_record_id')
            ->filter()
            ->all();

        DB::table('attendance_anomalies')
            ->whereIn('anomaly_type', self::TYPES)
            ->where('status', 'resolved')
            ->where('resolution_notes', self::MARKER)
            ->update([
                'status' => 'open',
                'resolved_at' => null,
                'resolution_notes' => null,
            ]);

        $this->recountAnomalies($recordIds);
    }

    /**
     * Recompute the open-anomaly flag/count on the affected attendance records
     * so list badges stay consistent. Plain per-record updates (portable on
     * MariaDB and SQLite; the affected set is a one-shot backlog).
     *
     * @param  array  $recordIds  Attendance record ids whose anomalies changed.
     */
    private function recountAnomalies(array $recordIds): void
    {
        foreach ($recordIds as $recordId) {
            $open = DB::table('attendance_anomalies')
                ->where('attendance_record_id', $recordId)
                ->where('status', 'open')
                ->count();

            DB::table('attendance_records')
                ->where('id', $recordId)
                ->update([
                    'has_anomalies' => $open > 0,
                    'anomaly_count' => $open,
                ]);
        }
    }
};
