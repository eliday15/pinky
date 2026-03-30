<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate existing administrative authorizations (exit_permission, entry_permission, schedule_change)
 * to the incidents table, then delete the originals.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $typeMap = [
            'exit_permission' => 'PSA',
            'entry_permission' => 'PEN',
            'schedule_change' => 'CDH',
        ];

        // Build incident_type_id lookup
        $incidentTypes = DB::table('incident_types')
            ->whereIn('code', array_values($typeMap))
            ->pluck('id', 'code');

        $authorizations = DB::table('authorizations')
            ->whereIn('type', array_keys($typeMap))
            ->get();

        foreach ($authorizations as $auth) {
            $code = $typeMap[$auth->type];
            $incidentTypeId = $incidentTypes[$code] ?? null;

            if (! $incidentTypeId) {
                continue;
            }

            DB::table('incidents')->insert([
                'employee_id' => $auth->employee_id,
                'incident_type_id' => $incidentTypeId,
                'start_date' => $auth->date,
                'end_date' => $auth->date,
                'start_time' => $auth->start_time,
                'end_time' => $auth->end_time,
                'hours' => $auth->hours,
                'days_count' => 1,
                'reason' => $auth->reason,
                'status' => $auth->status === 'paid' ? 'approved' : $auth->status,
                'approved_by' => $auth->approved_by,
                'approved_at' => $auth->approved_at,
                'rejection_reason' => $auth->rejection_reason,
                'migrated_from_authorization_id' => $auth->id,
                'created_at' => $auth->created_at,
                'updated_at' => now(),
            ]);
        }

        // Delete migrated authorizations
        DB::table('authorizations')
            ->whereIn('type', array_keys($typeMap))
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse: move incidents with migrated_from_authorization_id back to authorizations
        // This is a best-effort reverse — manual review recommended
        $incidents = DB::table('incidents')
            ->whereNotNull('migrated_from_authorization_id')
            ->get();

        $codeToType = [
            'PSA' => 'exit_permission',
            'PEN' => 'entry_permission',
            'CDH' => 'schedule_change',
        ];

        $incidentTypes = DB::table('incident_types')
            ->whereIn('code', array_keys($codeToType))
            ->pluck('code', 'id');

        foreach ($incidents as $incident) {
            $code = $incidentTypes[$incident->incident_type_id] ?? null;
            $authType = $codeToType[$code] ?? null;

            if (! $authType) {
                continue;
            }

            DB::table('authorizations')->insert([
                'id' => $incident->migrated_from_authorization_id,
                'employee_id' => $incident->employee_id,
                'type' => $authType,
                'date' => $incident->start_date,
                'start_time' => $incident->start_time,
                'end_time' => $incident->end_time,
                'hours' => $incident->hours,
                'reason' => $incident->reason,
                'status' => $incident->status,
                'approved_by' => $incident->approved_by,
                'approved_at' => $incident->approved_at,
                'rejection_reason' => $incident->rejection_reason,
                'created_at' => $incident->created_at,
                'updated_at' => now(),
            ]);
        }

        DB::table('incidents')
            ->whereNotNull('migrated_from_authorization_id')
            ->delete();
    }
};
