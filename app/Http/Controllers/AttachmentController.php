<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @group 附件管理
 *
 * 文件附件的上传、查询和删除操作，支持S3兼容存储
 */
class AttachmentController extends ApiController
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
     * 生成预签名上传URL
     *
     * 生成S3兼容存储的预签名上传URL，前端可直接使用此URL上传文件。
     * 上传完成后需调用确认接口创建附件记录。
     *
     * @bodyParam attachable_type string required 关联类型，可选值：invoice、payment Example: invoice
     * @bodyParam attachable_id integer required 关联实体ID Example: 1
     * @bodyParam filename string required 原始文件名，最大255字符 Example: 发票照片.jpg
     * @bodyParam file_size integer required 文件大小(字节)，最大10485760(10MB) Example: 102400
     * @bodyParam mime_type string required 文件MIME类型，支持：image/jpeg、image/png、image/gif、image/webp、application/pdf、application/msword、application/vnd.openxmlformats-officedocument.wordprocessingml.document、application/vnd.ms-excel、application/vnd.openxmlformats-officedocument.spreadsheetml.sheet、text/plain Example: image/jpeg
     *
     * @response 200 scenario="生成成功" {
     *   "success": true,
     *   "data": {
     *     "upload_url": "https://s3.example.com/bucket/path/file.jpg?X-Amz-Algorithm=...",
     *     "file_path": "attachments/invoices/2024/01/1/1704067200_abc12345_发票照片.jpg",
     *     "original_mime_type": "image/jpeg",
     *     "expires_in": 1200,
     *     "upload_instructions": {
     *       "method": "PUT",
     *       "content_type": null,
     *       "note": "重要：请设置Content-Type为null，让浏览器自动处理"
     *     }
     *   },
     *   "message": "预签名URL生成成功"
     * }
     * @response 404 scenario="关联实体不存在" {
     *   "success": false,
     *   "message": "关联实体不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="验证失败" {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "mime_type": ["不支持的文件类型"]
     *   }
     * }
     * @response 500 scenario="生成失败" {
     *   "success": false,
     *   "message": "预签名URL生成失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function generatePresignedUrl(Request $request)
    {
        $validated = $request->validate([
            'attachable_type' => 'required|in:invoice,payment',
            'attachable_id' => 'required|integer',
            'filename' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1|max:'.self::MAX_FILE_SIZE,
            'mime_type' => 'required|string|in:'.implode(',', self::ALLOWED_MIME_TYPES),
        ]);

        // 验证关联实体是否存在且用户有权限
        $entity = $this->getAttachableEntity($validated['attachable_type'], $validated['attachable_id']);
        if (! $entity) {
            return $this->errorResponse('关联实体不存在', 404);
        }

        if (! $this->canAccessEntity($entity)) {
            return $this->errorResponse('权限不足', 403);
        }

        // 生成安全的文件路径
        $filePath = $this->generateSecureFilePath(
            $validated['attachable_type'],
            $validated['attachable_id'],
            $validated['filename']
        );

        try {
            // 按缤纷云官方示例生成预签名URL
            $presignedUrl = $this->generateS3PresignedUrl($filePath);

            return $this->successResponse([
                'upload_url' => $presignedUrl,
                'file_path' => $filePath,
                'original_mime_type' => $validated['mime_type'],
                'expires_in' => config('app.attachment_presigned_url_expires', 20) * 60,
                'upload_instructions' => [
                    'method' => 'PUT',
                    'content_type' => null, // 关键：设置为null，让浏览器不设置Content-Type头
                    'note' => '重要：请设置Content-Type为null，让浏览器自动处理',
                ],
            ], '预签名URL生成成功');

        } catch (\Exception $e) {
            return $this->errorResponse('预签名URL生成失败：'.$e->getMessage(), 500);
        }
    }

    /**
     * 确认文件上传完成
     *
     * 确认文件已上传到对象存储，创建附件记录。
     * 需在调用预签名URL上传文件成功后调用此接口。
     *
     * @bodyParam attachable_type string required 关联类型，可选值：invoice、payment Example: invoice
     * @bodyParam attachable_id integer required 关联实体ID Example: 1
     * @bodyParam file_path string required 文件路径（从generatePresignedUrl返回） Example: attachments/invoices/2024/01/1/1704067200_abc12345_发票照片.jpg
     * @bodyParam original_filename string required 原始文件名，最大255字符 Example: 发票照片.jpg
     * @bodyParam file_size integer required 文件大小(字节)，最大10485760(10MB) Example: 102400
     * @bodyParam mime_type string required 文件MIME类型 Example: image/jpeg
     *
     * @response 201 scenario="创建成功" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "attachable_type": "App\\Models\\Invoice",
     *     "attachable_id": 1,
     *     "original_filename": "发票照片.jpg",
     *     "stored_filename": "1704067200_abc12345_发票照片.jpg",
     *     "file_path": "attachments/invoices/2024/01/1/1704067200_abc12345_发票照片.jpg",
     *     "file_size": 102400,
     *     "mime_type": "image/jpeg",
     *     "uploaded_by": 1,
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z",
     *     "uploaded_by_user": {
     *       "id": 1,
     *       "name": "管理员"
     *     }
     *   },
     *   "message": "附件上传成功"
     * }
     * @response 404 scenario="关联实体不存在" {
     *   "success": false,
     *   "message": "关联实体不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 422 scenario="文件验证失败" {
     *   "success": false,
     *   "message": "文件上传验证失败：文件不存在于对象存储中"
     * }
     * @response 500 scenario="保存失败" {
     *   "success": false,
     *   "message": "附件记录保存失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function confirmUpload(Request $request)
    {
        $validated = $request->validate([
            'attachable_type' => 'required|in:invoice,payment',
            'attachable_id' => 'required|integer',
            'file_path' => 'required|string',
            'original_filename' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1|max:'.self::MAX_FILE_SIZE,
            'mime_type' => 'required|string|in:'.implode(',', self::ALLOWED_MIME_TYPES),
        ]);

        // 验证关联实体是否存在且用户有权限
        $entity = $this->getAttachableEntity($validated['attachable_type'], $validated['attachable_id']);
        if (! $entity) {
            return $this->errorResponse('关联实体不存在', 404);
        }

        if (! $this->canAccessEntity($entity)) {
            \Log::warning('附件上传确认失败：权限不足', [
                'attachable_type' => $validated['attachable_type'],
                'attachable_id' => $validated['attachable_id'],
                'user_id' => Auth::id(),
                'entity_store_id' => $entity->store_id ?? null,
            ]);

            return $this->errorResponse('权限不足', 403);
        }

        // 验证文件是否真的上传到了对象存储
        $fileVerificationResult = $this->verifyFileExistsWithDetails($validated['file_path']);
        if (! $fileVerificationResult['exists']) {
            \Log::error('附件上传确认失败：文件验证失败', [
                'file_path' => $validated['file_path'],
                'user_id' => Auth::id(),
                'verification_details' => $fileVerificationResult,
                'request_data' => $validated,
            ]);

            return $this->errorResponse(
                '文件上传验证失败：'.$fileVerificationResult['error_message'],
                422
            );
        }

        try {
            // 创建附件记录
            $attachment = Attachment::create([
                'attachable_type' => $this->getModelClass($validated['attachable_type']),
                'attachable_id' => $validated['attachable_id'],
                'original_filename' => $validated['original_filename'],
                'stored_filename' => basename($validated['file_path']),
                'file_path' => $validated['file_path'],
                'file_size' => $validated['file_size'],
                'mime_type' => $validated['mime_type'],
                'uploaded_by' => Auth::id(),
            ]);

            // 加载关联数据
            $attachment->load('uploadedBy');

            Log::info('附件上传确认成功', [
                'attachment_id' => $attachment->id,
                'file_path' => $validated['file_path'],
                'user_id' => Auth::id(),
                'file_size' => $validated['file_size'],
                'mime_type' => $validated['mime_type'],
            ]);

            return $this->successResponse($attachment, '附件上传成功', 201);

        } catch (\Exception $e) {
            Log::error('附件记录保存失败', [
                'file_path' => $validated['file_path'],
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $validated,
            ]);

            return $this->errorResponse('附件记录保存失败：'.$e->getMessage(), 500);
        }
    }

    /**
     * 获取附件列表
     *
     * 获取指定实体（账单或还款）的附件列表。
     *
     * @queryParam attachable_type string required 关联类型，可选值：invoice、payment Example: invoice
     * @queryParam attachable_id integer required 关联实体ID Example: 1
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "attachable_type": "App\\Models\\Invoice",
     *       "attachable_id": 1,
     *       "original_filename": "发票照片.jpg",
     *       "stored_filename": "1704067200_abc12345_发票照片.jpg",
     *       "file_path": "attachments/invoices/2024/01/1/1704067200_abc12345_发票照片.jpg",
     *       "file_size": 102400,
     *       "mime_type": "image/jpeg",
     *       "uploaded_by": 1,
     *       "created_at": "2024-01-01T00:00:00.000000Z",
     *       "updated_at": "2024-01-01T00:00:00.000000Z",
     *       "uploaded_by_user": {
     *         "id": 1,
     *         "name": "管理员"
     *       }
     *     }
     *   ]
     * }
     * @response 404 scenario="关联实体不存在" {
     *   "success": false,
     *   "message": "关联实体不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'attachable_type' => 'required|in:invoice,payment',
            'attachable_id' => 'required|integer',
        ]);

        // 验证关联实体是否存在且用户有权限
        $entity = $this->getAttachableEntity($validated['attachable_type'], $validated['attachable_id']);
        if (! $entity) {
            return $this->errorResponse('关联实体不存在', 404);
        }

        if (! $this->canAccessEntity($entity)) {
            return $this->errorResponse('权限不足', 403);
        }

        // 获取附件列表
        $attachments = $entity->attachments()
            ->with('uploadedBy:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse($attachments);
    }

    /**
     * 删除附件
     *
     * 删除指定附件，同时从对象存储中删除文件。
     *
     * @urlParam attachment integer required 附件ID Example: 1
     *
     * @response 200 scenario="删除成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "附件删除成功"
     * }
     * @response 404 scenario="附件不存在" {
     *   "success": false,
     *   "message": "资源不存在"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "权限不足"
     * }
     * @response 500 scenario="删除失败" {
     *   "success": false,
     *   "message": "附件删除失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function destroy($id)
    {
        $attachment = Attachment::findOrFail($id);

        // 验证用户是否有权限删除该附件
        if (! $this->canAccessEntity($attachment->attachable)) {
            return $this->errorResponse('权限不足', 403);
        }

        try {
            // 从对象存储删除文件
            $this->deleteFileFromStorage($attachment->file_path);

            // 删除数据库记录
            $attachment->delete();

            return $this->successResponse(null, '附件删除成功');

        } catch (\Exception $e) {
            return $this->errorResponse('附件删除失败：'.$e->getMessage(), 500);
        }
    }

    /**
     * 获取关联实体
     */
    private function getAttachableEntity(string $type, int $id)
    {
        switch ($type) {
            case 'invoice':
                return Invoice::find($id);
            case 'payment':
                return Payment::find($id);
            default:
                return null;
        }
    }

    /**
     * 检查用户是否可以访问实体
     */
    private function canAccessEntity($entity): bool
    {
        if (! $entity) {
            return false;
        }

        // 系统管理员可以访问所有实体
        if ($this->isAdmin()) {
            return true;
        }

        // 检查用户是否属于实体所在的门店
        return $this->belongsToStore($entity->store_id);
    }

    /**
     * 获取模型类名
     */
    private function getModelClass(string $type): string
    {
        switch ($type) {
            case 'invoice':
                return Invoice::class;
            case 'payment':
                return Payment::class;
            default:
                throw new \InvalidArgumentException('Invalid attachable type');
        }
    }

    /**
     * 生成安全的文件路径
     */
    private function generateSecureFilePath(string $type, int $id, string $filename): string
    {
        $now = now();
        $year = $now->format('Y');
        $month = $now->format('m');
        $timestamp = $now->timestamp;

        // 清理文件名，防止路径遍历攻击（支持中文字符）
        $safeFilename = $this->sanitizeFilename($filename);

        // 生成唯一的文件名
        $hash = substr(md5($timestamp.$id.$safeFilename), 0, 8);
        $storedFilename = $timestamp.'_'.$hash.'_'.$safeFilename;

        return "attachments/{$type}s/{$year}/{$month}/{$id}/{$storedFilename}";
    }

    /**
     * 清理文件名，支持中文字符
     */
    private function sanitizeFilename(string $filename): string
    {
        if (empty($filename)) {
            return 'unnamed_file';
        }

        // 移除危险字符，保留中文字符（使用Unicode正则表达式）
        $safeFilename = preg_replace('/[^\p{L}\p{N}\.\-_]/u', '_', $filename);
        $safeFilename = trim($safeFilename, '.');

        // 限制长度
        if (strlen($safeFilename) > 100) {
            $extension = pathinfo($safeFilename, PATHINFO_EXTENSION);
            $name = pathinfo($safeFilename, PATHINFO_FILENAME);
            $safeFilename = substr($name, 0, 95).'.'.$extension;
        }

        return $safeFilename ?: 'unnamed_file';
    }

    /**
     * 生成S3兼容存储预签名URL
     */
    private function generateS3PresignedUrl(string $filePath, ?string $contentType = null): string
    {
        $config = config('filesystems.disks.s3-compat');

        // 确保endpoint格式正确
        $endpoint = $config['endpoint'];
        if (! str_starts_with($endpoint, 'http://') && ! str_starts_with($endpoint, 'https://')) {
            $endpoint = 'https://'.$endpoint;
        }

        try {
            // 配置S3客户端
            $s3Client = new \Aws\S3\S3Client([
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
                'use_path_style_endpoint' => false, // 官方文档使用false
                'use_aws_shared_config_files' => false, // 官方文档建议
                'endpoint' => $endpoint,
                'signature_version' => 'v4', // 官方文档明确使用v4
                'version' => 'latest',
                'region' => $config['region'],
                'http' => [
                    'verify' => false,
                ],
            ]);

            // 创建PutObject命令
            $command = $s3Client->getCommand('PutObject', [
                'Bucket' => $config['bucket'],
                'Key' => $filePath,
            ]);

            // 生成预签名URL
            $request = $s3Client->createPresignedRequest(
                $command,
                '+'.config('app.attachment_presigned_url_expires', 20).' minutes'
            );

            return (string) $request->getUri();

        } catch (\Exception $e) {
            // 如果官方方式失败，使用备用的v2签名
            return $this->generateManualV2Signature($config, $filePath, $endpoint);
        }
    }

    /**
     * 手动生成v2签名（S3兼容存储备用方案）
     */
    private function generateManualV2Signature(array $config, string $filePath, ?string $endpoint = null): string
    {
        $expires = time() + (config('app.attachment_presigned_url_expires', 20) * 60);
        $method = 'PUT';
        $bucket = $config['bucket'];

        // 使用传入的endpoint或配置中的endpoint
        $baseEndpoint = $endpoint ?: $config['endpoint'];

        // 确保endpoint格式正确
        if (! str_starts_with($baseEndpoint, 'http://') && ! str_starts_with($baseEndpoint, 'https://')) {
            $baseEndpoint = 'https://'.$baseEndpoint;
        }

        // 构建签名字符串（v2格式）
        $stringToSign = implode("\n", [
            $method,                    // HTTP方法
            '',                         // Content-MD5 (空)
            '',                         // Content-Type (空，让浏览器自动设置)
            $expires,                   // Expires
            "/{$bucket}/{$filePath}",    // 资源路径
        ]);

        // 使用HMAC-SHA1生成签名
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $config['secret'], true));

        // 构建预签名URL
        $baseUrl = rtrim($baseEndpoint, '/').'/'.$bucket.'/'.$filePath;
        $queryParams = [
            'AWSAccessKeyId' => $config['key'],
            'Expires' => $expires,
            'Signature' => $signature,
        ];

        return $baseUrl.'?'.http_build_query($queryParams);
    }

    /**
     * 验证文件是否存在于对象存储（带详细调试信息）
     */
    private function verifyFileExistsWithDetails(string $filePath): array
    {
        $result = [
            'exists' => false,
            'error_message' => '',
            'debug_info' => [],
        ];

        try {
            // 获取存储磁盘配置
            $diskName = config('app.attachment_disk', 's3-compat');
            $diskConfig = config("filesystems.disks.{$diskName}");

            $result['debug_info']['disk_name'] = $diskName;
            $result['debug_info']['disk_config'] = [
                'driver' => $diskConfig['driver'] ?? 'unknown',
                'region' => $diskConfig['region'] ?? 'unknown',
                'bucket' => $diskConfig['bucket'] ?? 'unknown',
                'endpoint' => $diskConfig['endpoint'] ?? 'unknown',
                'key_configured' => ! empty($diskConfig['key']),
                'secret_configured' => ! empty($diskConfig['secret']),
            ];

            // 尝试获取存储磁盘实例
            $disk = Storage::disk($diskName);
            $result['debug_info']['disk_instance_created'] = true;

            // 测试基本连接 - 尝试列出根目录
            try {
                $disk->files('', false); // 只列出根目录文件，不递归
                $result['debug_info']['connection_test'] = 'success';
            } catch (\Exception $connEx) {
                $result['debug_info']['connection_test'] = 'failed';
                $result['debug_info']['connection_error'] = $connEx->getMessage();
                Log::warning('存储连接测试失败', [
                    'error' => $connEx->getMessage(),
                    'trace' => $connEx->getTraceAsString(),
                ]);
            }

            // 验证文件是否存在
            $fileExists = $disk->exists($filePath);
            $result['exists'] = $fileExists;
            $result['debug_info']['file_exists_result'] = $fileExists;

            if ($fileExists) {
                // 如果文件存在，获取更多信息
                try {
                    $fileSize = $disk->size($filePath);
                    $lastModified = $disk->lastModified($filePath);
                    $result['debug_info']['file_info'] = [
                        'size' => $fileSize,
                        'last_modified' => date('Y-m-d H:i:s', $lastModified),
                    ];
                } catch (\Exception $infoEx) {
                    Log::warning('获取文件信息失败', ['error' => $infoEx->getMessage()]);
                }
            } else {
                Log::warning('文件不存在于对象存储', ['file_path' => $filePath]);
                $result['error_message'] = '文件不存在于对象存储中';
            }

        } catch (\Exception $e) {
            $result['exists'] = false;
            $result['error_message'] = $e->getMessage();
            $result['debug_info']['exception'] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];

            Log::error('文件存在性验证异常', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $result;
    }

    /**
     * 验证文件是否存在于对象存储（简化版本，保持向后兼容）
     */
    private function verifyFileExists(string $filePath): bool
    {
        $result = $this->verifyFileExistsWithDetails($filePath);

        return $result['exists'];
    }

    /**
     * 测试S3存储连接状态
     */
    private function testS4Connection(): array
    {
        try {
            $diskName = config('app.attachment_disk', 's3-compat');
            $disk = Storage::disk($diskName);

            // 尝试列出存储桶根目录来测试连接
            $files = $disk->files('', false);

            return [
                'success' => true,
                'message' => '连接成功',
                'file_count' => count($files),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * 从对象存储删除文件
     */
    private function deleteFileFromStorage(string $filePath): void
    {
        try {
            $disk = Storage::disk(config('app.attachment_disk', 's3-compat'));

            // 直接尝试删除，不依赖 exists() 检查
            // S3 的 exists() 检查可能因为权限或配置问题返回 false
            $deleted = $disk->delete($filePath);

            if ($deleted) {
                Log::info('文件删除成功', ['file_path' => $filePath]);
            } else {
                Log::warning('文件删除返回false，可能文件不存在', ['file_path' => $filePath]);
            }
        } catch (\Exception $e) {
            Log::error('文件删除失败', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 不抛出异常，允许继续删除数据库记录
            // 文件可能已经不存在或权限问题
        }
    }
}
