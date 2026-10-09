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
        Schema::create('installs', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // pma, caddy, site, …
            $table->string('label');
            $table->string('command'); // panel:* command executed by the runner
            $table->string('status', 16)->default('pending'); // pending|running|success|failed
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installs');
    }
};
