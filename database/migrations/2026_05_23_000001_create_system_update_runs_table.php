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
        Schema::create('system_update_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tag');
            $table->string('version')->nullable();
            $table->string('status')->index();
            $table->string('step')->nullable();
            $table->json('metadata')->nullable();
            $table->json('log_lines')->nullable();
            $table->string('backup_path')->nullable();
            $table->string('package_path')->nullable();
            $table->string('package_sha256')->nullable();
            $table->text('error_message')->nullable();
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
        Schema::dropIfExists('system_update_runs');
    }
};
