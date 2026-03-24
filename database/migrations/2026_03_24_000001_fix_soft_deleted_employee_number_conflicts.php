<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Append '_deleted_{id}' to employee_number of soft-deleted employees
 * to free up the unique constraint for reuse by new employees.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->whereNotNull('deleted_at')
            ->where('employee_number', 'not like', '%_deleted_%')
            ->orderBy('id')
            ->each(function ($employee) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'employee_number' => $employee->employee_number . '_deleted_' . $employee->id,
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('employees')
            ->whereNotNull('deleted_at')
            ->where('employee_number', 'like', '%_deleted_%')
            ->orderBy('id')
            ->each(function ($employee) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'employee_number' => preg_replace('/_deleted_\d+$/', '', $employee->employee_number),
                    ]);
            });
    }
};
