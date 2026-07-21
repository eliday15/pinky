<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enriches audit_logs so every entry answers "who did what, to whom".
     *
     * Adds an actor snapshot (name + role at the time of the event, so the
     * trail survives user deletion/rename), the execution context (web,
     * console, sync, kiosk), the affected employee, a human readable subject
     * label and free-form metadata for semantic events.
     *
     * Written idempotently: production boot runs `migrate --force` and
     * swallows errors, so re-running must never fail.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'actor_name')) {
                $table->string('actor_name')->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('audit_logs', 'actor_role')) {
                $table->string('actor_role')->nullable()->after('actor_name');
            }

            if (! Schema::hasColumn('audit_logs', 'context')) {
                $table->string('context', 20)->nullable()->after('actor_role');
            }

            if (! Schema::hasColumn('audit_logs', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('auditable_id');
            }

            if (! Schema::hasColumn('audit_logs', 'subject_label')) {
                $table->string('subject_label')->nullable()->after('employee_id');
            }

            if (! Schema::hasColumn('audit_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('new_values');
            }
        });

        // auditable_type was NOT NULL but the writer always allowed null
        // (e.g. login/logout events have no model). Relax it.
        if (Schema::hasColumn('audit_logs', 'auditable_type')) {
            $this->makeAuditableTypeNullable();
        }

        $this->addIndexIfMissing('audit_logs_user_id_created_at_index', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'audit_logs_user_id_created_at_index');
        });

        $this->addIndexIfMissing('audit_logs_employee_id_created_at_index', function (Blueprint $table) {
            $table->index(['employee_id', 'created_at'], 'audit_logs_employee_id_created_at_index');
        });
    }

    /**
     * Relax auditable_type to nullable across drivers.
     */
    private function makeAuditableTypeNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        try {
            match ($driver) {
                'pgsql' => Schema::getConnection()->statement(
                    'ALTER TABLE audit_logs ALTER COLUMN auditable_type DROP NOT NULL'
                ),
                'mysql', 'mariadb' => Schema::getConnection()->statement(
                    'ALTER TABLE audit_logs MODIFY auditable_type VARCHAR(255) NULL'
                ),
                // SQLite cannot alter nullability in place; test databases are
                // rebuilt from scratch so the column is already nullable there
                // once the create migration is re-run.
                default => null,
            };
        } catch (\Throwable) {
            // Already nullable, or the driver refuses a no-op change.
        }
    }

    /**
     * Add an index only when it does not already exist.
     */
    private function addIndexIfMissing(string $indexName, callable $definition): void
    {
        try {
            $existing = Schema::getIndexes('audit_logs');
        } catch (\Throwable) {
            $existing = [];
        }

        foreach ($existing as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return;
            }
        }

        try {
            Schema::table('audit_logs', $definition);
        } catch (\Throwable) {
            // Index already present under a different name.
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (['actor_name', 'actor_role', 'context', 'employee_id', 'subject_label', 'metadata'] as $column) {
                if (Schema::hasColumn('audit_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
