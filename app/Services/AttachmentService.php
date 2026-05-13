<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AttachmentService
{
    /**
     * 允许的文件类型
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    /**
     * 最大文件大小 (10MB)
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * 验证文件参数
     */
    public function validateFileParams(array $params): array
    {
        $validator = Validator::make($params, [
            'filename' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1|max:' . self::MAX_FILE_SIZE,
            'mime_type' => 'required|string|in:' . implode(',', self::ALLOWED_MIME_TYPES),
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->first());
        }

        return $validator->validated();
    }

    /**
     * 生成安全的文件路径
     */
    public function generateSecureFilePath(string $type, int $id, string $filename): string
    {
        $now = now();
        $year = $now->format('Y');
        $month = $now->format('m');
        $timestamp = $now->timestamp;
        
        // 清理文件名，防止路径遍历攻击
        $safeFilename = $this->sanitizeFilename($filename);
        
        // 生成唯一的文件名
        $hash = substr(md5($timestamp . $id . $safeFilename), 0, 8);
        $storedFilename = $timestamp . '_' . $hash . '_' . $safeFilename;
        
        return "attachments/{$type}s/{$year}/{$month}/{$id}/{$storedFilename}";
    }

    /**
     * 清理文件名
     */
    public function sanitizeFilename(string $filename): string
    {
        if (empty($filename)) {
            return 'unnamed_file';
        }

        // 移除危险字符，保留中文字符
        $safeFilename = preg_replace('/[^\p{L}\p{N}\.\-_]/u', '_', $filename);
        $safeFilename = trim($safeFilename, '.');

        // 限制长度
        if (strlen($safeFilename) > 100) {
            $extension = pathinfo($safeFilename, PATHINFO_EXTENSION);
            $name = pathinfo($safeFilename, PATHINFO_FILENAME);
            $safeFilename = substr($name, 0, 95) . '.' . $extension;
        }

        return $safeFilename ?: 'unnamed_file';
    }

    /**
     * 验证文件是否存在于对象存储
     */
    public function verifyFileExists(string $filePath): bool
    {
        try {
            $disk = Storage::disk(config('app.attachment_disk', 's3-compat'));
            return $disk->exists($filePath);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 从对象存储删除文件
     */
    public function deleteFileFromStorage(string $filePath): bool
    {
        try {
            $disk = Storage::disk(config('app.attachment_disk', 's3-compat'));
            
            if ($disk->exists($filePath)) {
                return $disk->delete($filePath);
            }
            
            return true; // 文件不存在也算删除成功
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取文件下载URL
     */
    public function getFileDownloadUrl(Attachment $attachment, int $expiresInMinutes = 60): string
    {
        $disk = Storage::disk(config('app.attachment_disk', 's3-compat'));
        
        try {
            return $disk->temporaryUrl($attachment->file_path, now()->addMinutes($expiresInMinutes));
        } catch (\Exception $e) {
            throw new \RuntimeException('无法生成文件下载链接：' . $e->getMessage());
        }
    }

    /**
     * 获取允许的文件类型列表
     */
    public function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    /**
     * 获取最大文件大小
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    /**
     * 检查文件类型是否被允许
     */
    public function isAllowedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES);
    }

    /**
     * 格式化文件大小
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
