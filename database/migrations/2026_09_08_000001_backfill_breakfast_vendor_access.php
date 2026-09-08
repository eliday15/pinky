<?php

use App\Models\Employee;
use App\Models\SystemSetting;
use App\Services\BreakfastVendorAccessService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'breakfasts.register',
            'guard_name' => 'web',
        ]);
        $role = Role::firstOrCreate([
            'name' => BreakfastVendorAccessService::ROLE,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([$permission]);

        $vendor = Employee::with('user')->find(
            (int) SystemSetting::get('breakfast_vendor_employee_id', 0)
        );

        if ($vendor?->user) {
            $vendor->user->assignRole($role);
        }
    }

    public function down(): void
    {
        Role::where('name', BreakfastVendorAccessService::ROLE)
            ->where('guard_name', 'web')
            ->delete();
    }
};
