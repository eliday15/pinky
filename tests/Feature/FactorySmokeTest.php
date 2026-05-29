<?php

namespace Tests\Feature;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\EmergencyContact;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\LateAccumulation;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\SyncLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VacationTable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/**
 * Proves every model factory produces a persistable row. If a factory
 * references a wrong column, an invalid enum value, or a missing foreign
 * key, this test surfaces it before any domain test relies on it.
 */
class FactorySmokeTest extends FeatureTestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function factoryProvider(): array
    {
        return [
            'User' => [User::class],
            'Department' => [Department::class],
            'Position' => [Position::class],
            'Schedule' => [Schedule::class],
            'Employee' => [Employee::class],
            'CompensationType' => [CompensationType::class],
            'AttendanceRecord' => [AttendanceRecord::class],
            'AttendanceAnomaly' => [AttendanceAnomaly::class],
            'SyncLog' => [SyncLog::class],
            'LateAccumulation' => [LateAccumulation::class],
            'IncidentType' => [IncidentType::class],
            'Incident' => [Incident::class],
            'Authorization' => [Authorization::class],
            'EmergencyContact' => [EmergencyContact::class],
            'PayrollPeriod' => [PayrollPeriod::class],
            'PayrollEntry' => [PayrollEntry::class],
            'Holiday' => [Holiday::class],
            'SystemSetting' => [SystemSetting::class],
            'VacationTable' => [VacationTable::class],
            'AuditLog' => [AuditLog::class],
        ];
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     */
    #[DataProvider('factoryProvider')]
    public function test_factory_creates_persistable_row(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertNotNull($model->getKey(), "{$modelClass} factory did not persist a row");
        $this->assertDatabaseHas($model->getTable(), [$model->getKeyName() => $model->getKey()]);
    }
}
