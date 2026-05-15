<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_upload_intents', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('file_path', 500)->unique();
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 100);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index(['uploaded_by', 'consumed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_upload_intents');
    }
};
