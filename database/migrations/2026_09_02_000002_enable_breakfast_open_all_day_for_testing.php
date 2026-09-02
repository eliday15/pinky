<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SystemSetting::set('breakfast_open_all_day', true);
    }

    public function down(): void
    {
        SystemSetting::set('breakfast_open_all_day', false);
    }
};
