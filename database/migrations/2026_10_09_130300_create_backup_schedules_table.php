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
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16); // database|project
            $table->unsignedBigInteger('target_id');
            $table->unsignedTinyInteger('times_per_day')->default(1); // 1-24
            $table->unsignedSmallInteger('retention_days')->default(7); // 1-90
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable(); // success|failed
            $table->text('last_error')->nullable();
            $table->string('last_path', 300)->nullable();
            $table->timestamps();

            $table->unique(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
