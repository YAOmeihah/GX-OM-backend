<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use Auditable, HasFactory;

    /**
     * 审计日志排除的字段
     */
    protected array $auditExcludeFields = ['updated_at'];

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'original_filename',
        'stored_filename',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();

        // 删除附件记录时，同时删除未被其他附件引用的存储文件
        static::deleting(function (Attachment $attachment) {
            if (empty($attachment->file_path)) {
                return;
            }

            $hasOtherReferences = static::where('file_path', $attachment->file_path)
                ->whereKeyNot($attachment->getKey())
                ->exists();

            if ($hasOtherReferences) {
                return;
            }

            try {
                Storage::disk(config('app.attachment_disk', 's3-compat'))->delete($attachment->file_path);
            } catch (\Exception $e) {
                // 记录日志但不阻止删除操作
                \Log::warning("Failed to delete attachment file: {$attachment->file_path}", [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 自动追加到序列化结果的属性
     */
    protected $appends = ['url', 'thumbnail_url', 'filename'];

    /**
     * 获取关联的模型（多态关联）
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 获取上传用户
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * 获取文件大小的可读格式
     */
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * 检查是否为图片文件
     */
    public function isImage(): bool
    {
        return ! empty($this->mime_type) && str_starts_with($this->mime_type, 'image/');
    }

    /**
     * 检查是否为PDF文件
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * 检查是否为文档文件
     */
    public function isDocument(): bool
    {
        $documentTypes = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];

        return in_array($this->mime_type, $documentTypes);
    }

    /**
     * 获取文件访问URL
     *
     * 使用S3兼容存储的URL配置生成完整的文件访问地址
     */
    public function getUrlAttribute(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        // 获取S3 URL基础地址
        $baseUrl = config('filesystems.disks.s3-compat.url');

        if (empty($baseUrl)) {
            // 如果没有配置 URL，使用 endpoint + bucket 构建
            $endpoint = config('filesystems.disks.s3-compat.endpoint');
            $bucket = config('filesystems.disks.s3-compat.bucket');

            if (! empty($endpoint) && ! empty($bucket)) {
                // 确保 endpoint 格式正确
                if (! str_starts_with($endpoint, 'http://') && ! str_starts_with($endpoint, 'https://')) {
                    $endpoint = 'https://'.$endpoint;
                }
                $baseUrl = rtrim($endpoint, '/').'/'.$bucket;
            }
        }

        if (empty($baseUrl)) {
            return null;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($this->file_path, '/');
    }

    /**
     * 获取图片缩略图URL
     *
     * 仅对图片类型附件生成缩略图URL，使用OSS图片处理参数
     * 非图片类型返回null
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        // 非图片不需要缩略图
        if (! $this->isImage()) {
            return null;
        }

        // 获取原始URL
        $originalUrl = $this->url;
        if (empty($originalUrl)) {
            return null;
        }

        // 添加OSS图片处理参数：300x300 裁切模式
        $separator = str_contains($originalUrl, '?') ? '&' : '?';

        return $originalUrl.$separator.'w=300&h=300&mode=crop';
    }

    /**
     * 获取文件名访问器
     *
     * 返回存储的文件名，用于前端显示
     */
    public function getFilenameAttribute(): ?string
    {
        return $this->stored_filename;
    }
}
