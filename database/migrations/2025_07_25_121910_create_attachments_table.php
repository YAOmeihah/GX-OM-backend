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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type')->comment('关联模型类型');
            $table->unsignedBigInteger('attachable_id')->comment('关联模型ID');
            $table->string('original_filename')->comment('原始文件名');
            $table->string('stored_filename')->comment('存储文件名');
            $table->string('file_path', 500)->comment('文件存储路径');
            $table->unsignedBigInteger('file_size')->comment('文件大小(字节)');
            $table->string('mime_type', 100)->comment('文件MIME类型');
            $table->foreignId('uploaded_by')->constrained('users')->comment('上传用户ID');
            $table->timestamps();

            // 添加索引
            $table->index(['attachable_type', 'attachable_id']);
            $table->index('uploaded_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
