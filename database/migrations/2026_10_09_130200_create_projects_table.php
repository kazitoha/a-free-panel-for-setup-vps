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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('repo_url', 300);
            $table->string('branch')->default('main');
            $table->string('domain')->unique();
            $table->string('php_version', 8);
            $table->string('path');
            $table->foreignId('database_id')->nullable()->constrained('databases')->nullOnDelete();
            $table->timestamp('last_deployed_at')->nullable();
            $table->string('last_deploy_status', 16)->nullable(); // success|failed
            $table->text('last_deploy_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
