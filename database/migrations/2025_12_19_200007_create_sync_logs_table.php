<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['zkteco', 'manual_import']);
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->enum('status', ['running', 'completed', 'failed']);
            $table->integer('records_fetched')->default(0);
            $table->integer('records_processed')->default(0);
            $table->integer('records_created')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
