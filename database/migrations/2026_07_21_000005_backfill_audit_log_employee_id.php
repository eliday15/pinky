<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill audit_logs.employee_id for historical rows, so the "affected
     * employee" column and filter also work for entries written before the
     * column existed. Resolves the employee from the audited record itself.
     *
     * Idempotent: only touches rows where employee_id is still null, so the
     * boot-time `migrate --force` can re-run it harmlessly.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'employee_id')) {
            return;
        }

        // An Employee record is about itself.
        $this->safeStatement(
            "UPDATE audit_logs SET employee_id = auditable_id
             WHERE auditable_type = ? AND employee_id IS NULL AND auditable_id IS NOT NULL",
            [\App\Models\Employee::class],
        );

        // Records that carry an employee_id foreign key.
        $sources = [
            \App\Models\Authorization::class => 'authorizations',
            \App\Models\Incident::class => 'incidents',
            \App\Models\CheckOmission::class => 'check_omissions',
            \App\Models\AttendanceRecord::class => 'attendance_records',
            \App\Models\AttendanceAnomaly::class => 'attendance_anomalies',
            \App\Models\PayrollEntry::class => 'payroll_entries',
            \App\Models\CashPayout::class => 'cash_payouts',
            \App\Models\BreakfastClaim::class => 'breakfast_claims',
        ];

        foreach ($sources as $class => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'employee_id')) {
                continue;
            }

            // Correlated subquery form works on MySQL, PostgreSQL and SQLite;
            // the subquery reads a different table than the one being updated.
            $this->safeStatement(
                "UPDATE audit_logs
                 SET employee_id = (
                     SELECT t.employee_id FROM {$table} t WHERE t.id = audit_logs.auditable_id LIMIT 1
                 )
                 WHERE auditable_type = ?
                   AND employee_id IS NULL
                   AND auditable_id IN (SELECT id FROM {$table} WHERE employee_id IS NOT NULL)",
                [$class],
            );
        }
    }

    /**
     * Run a statement, swallowing errors so a partial/legacy schema can never
     * abort the boot migration run.
     */
    private function safeStatement(string $sql, array $bindings): void
    {
        try {
            DB::update($sql, $bindings);
        } catch (\Throwable) {
            // Table shape differs or the driver rejects the form — skip.
        }
    }

    /**
     * Reverse: nothing to undo (backfill only fills nulls).
     */
    public function down(): void
    {
        // No-op: we cannot know which employee_id values were backfilled.
    }
};
