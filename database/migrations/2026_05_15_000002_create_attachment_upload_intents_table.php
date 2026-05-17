<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'attachment_upload_intents';
        $actorTimingIndex = 'attachment_upload_intents_actor_timing_idx';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($actorTimingIndex) {
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
                $table->index(['uploaded_by', 'consumed_at', 'expires_at'], $actorTimingIndex);
            });

            return;
        }

        $indexes = Schema::getIndexListing($tableName);

        Schema::table($tableName, function (Blueprint $table) use ($indexes, $actorTimingIndex) {
            if (! in_array('attachment_upload_intents_file_path_unique', $indexes, true)) {
                $table->unique('file_path');
            }

            if (! in_array('attachment_upload_intents_attachable_type_attachable_id_index', $indexes, true)) {
                $table->index(['attachable_type', 'attachable_id']);
            }

            if (! in_array($actorTimingIndex, $indexes, true)) {
                $table->index(['uploaded_by', 'consumed_at', 'expires_at'], $actorTimingIndex);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_upload_intents');
    }
};
