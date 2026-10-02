<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('late_month', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sequence-bearing values cannot safely be truncated to YYYY-MM.
        // Keep the wider column when rolling back application code.
    }
};
