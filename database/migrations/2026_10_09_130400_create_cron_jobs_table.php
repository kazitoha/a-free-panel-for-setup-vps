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
        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cron_minute', 20)->default('*');
            $table->string('cron_hour', 20)->default('*');
            $table->string('cron_dom', 20)->default('*');
            $table->string('cron_month', 20)->default('*');
            $table->string('cron_dow', 20)->default('*');
            $table->string('command', 300);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable(); // ok|failed
            $table->integer('last_exit')->nullable();
            $table->text('last_output')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cron_jobs');
    }
};
