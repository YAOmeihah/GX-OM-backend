<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * @group 配置管理
 *
 * 系统配置的查询和更新（仅管理员）
 */
class ConfigController extends ApiController
{
    /**
     * 获取附件配置
     *
     * 获取S3存储和附件上传相关配置信息。仅系统管理员可访问。
     *
     * @response 200 scenario="获取成功" {
     *   "success": true,
     *   "data": {
     *     "disk": "s3-compat",
     *     "max_file_size": 10485760,
     *     "presigned_url_expires": 20,
     *     "allowed_mime_types": [
     *       "image/jpeg",
     *       "image/png",
     *       "image/gif",
     *       "image/webp",
     *       "application/pdf",
     *       "application/msword",
     *       "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
     *       "application/vnd.ms-excel",
     *       "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
     *       "text/plain"
     *     ],
     *     "s3_config": {
     *       "region": "auto",
     *       "bucket": "my-bucket",
     *       "endpoint": "https://s3.example.com",
     *       "access_key": "已配置",
     *       "secret_key": "已配置"
     *     }
     *   }
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function getAttachmentConfig()
    {
        // 只有系统管理员可以查看配置
        if (!$this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        $config = [
            'disk' => config('app.attachment_disk', 's3-compat'),
            'max_file_size' => config('app.attachment_max_size', 10485760),
            'presigned_url_expires' => config('app.attachment_presigned_url_expires', 20),
            'allowed_mime_types' => [
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
            ],
            's3_config' => [
                'region' => config('filesystems.disks.s3-compat.region'),
                'bucket' => config('filesystems.disks.s3-compat.bucket'),
                'endpoint' => config('filesystems.disks.s3-compat.endpoint'),
                'access_key' => config('filesystems.disks.s3-compat.key') ? '已配置' : '未配置',
                'secret_key' => config('filesystems.disks.s3-compat.secret') ? '已配置' : '未配置',
            ]
        ];

        return $this->successResponse($config);
    }

    /**
     * 更新S3存储配置
     *
     * 更新S3兼容存储的配置信息。仅系统管理员可执行。
     * 更新后会自动测试连接，测试失败则不会保存配置。
     *
     * @bodyParam access_key string required S3访问密钥 Example: AKIAIOSFODNN7EXAMPLE
     * @bodyParam secret_key string required S3秘密密钥 Example: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
     * @bodyParam region string required 存储区域 Example: auto
     * @bodyParam bucket string required 存储桶名称 Example: my-bucket
     * @bodyParam endpoint string required 存储服务端点URL Example: https://s3.example.com
     *
     * @response 200 scenario="更新成功" {
     *   "success": true,
     *   "data": null,
     *   "message": "S3存储配置更新成功"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限"
     * }
     * @response 422 scenario="连接测试失败" {
     *   "success": false,
     *   "message": "配置测试失败：Connection refused"
     * }
     * @response 500 scenario="更新失败" {
     *   "success": false,
     *   "message": "配置更新失败：..."
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function updateS3Config(Request $request)
    {
        // 只有系统管理员可以更新配置
        if (!$this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        $validated = $request->validate([
            'access_key' => 'required|string',
            'secret_key' => 'required|string',
            'region' => 'required|string',
            'bucket' => 'required|string',
            'endpoint' => 'required|url',
        ]);

        try {
            // 更新运行时配置
            Config::set('filesystems.disks.s3-compat.key', $validated['access_key']);
            Config::set('filesystems.disks.s3-compat.secret', $validated['secret_key']);
            Config::set('filesystems.disks.s3-compat.region', $validated['region']);
            Config::set('filesystems.disks.s3-compat.bucket', $validated['bucket']);
            Config::set('filesystems.disks.s3-compat.endpoint', $validated['endpoint']);

            // 测试连接
            $testResult = $this->testS3ConnectionInternal();

            if (!$testResult['success']) {
                return $this->errorResponse('配置测试失败：' . $testResult['message'], 422);
            }

            // 更新环境文件
            $this->updateEnvFile([
                'S3_ACCESS_KEY' => $validated['access_key'],
                'S3_SECRET_KEY' => $validated['secret_key'],
                'S3_REGION' => $validated['region'],
                'S3_BUCKET' => $validated['bucket'],
                'S3_ENDPOINT' => $validated['endpoint'],
            ]);

            return $this->successResponse(null, 'S3存储配置更新成功');

        } catch (\Exception $e) {
            return $this->errorResponse('配置更新失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 测试S3存储连接
     *
     * 测试当前S3兼容存储配置是否可以正常连接。仅系统管理员可执行。
     *
     * @response 200 scenario="连接成功" {
     *   "success": true,
     *   "data": {
     *     "status": "connected"
     *   },
     *   "message": "S3存储连接测试成功"
     * }
     * @response 403 scenario="权限不足" {
     *   "success": false,
     *   "message": "需要系统管理员权限"
     * }
     * @response 422 scenario="连接失败" {
     *   "success": false,
     *   "message": "连接测试失败：Connection refused"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function testS3Connection()
    {
        // 只有系统管理员可以测试连接
        if (!$this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        try {
            $disk = Storage::disk('s3-compat');

            // 尝试列出存储桶内容来测试连接
            $disk->files('', true);

            return $this->successResponse(['status' => 'connected'], 'S3存储连接测试成功');

        } catch (\Exception $e) {
            return $this->errorResponse('连接测试失败：' . $e->getMessage(), 422);
        }
    }

    /**
     * 测试连接的内部方法
     */
    private function testS3ConnectionInternal(): array
    {
        try {
            $disk = Storage::disk('s3-compat');
            $disk->files('', true);

            return ['success' => true, 'message' => '连接成功'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 更新环境文件
     */
    private function updateEnvFile(array $data): void
    {
        $envFile = base_path('.env');

        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env文件不存在');
        }

        $envContent = file_get_contents($envFile);

        foreach ($data as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $replacement = "{$key}={$value}";

            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        file_put_contents($envFile, $envContent);
    }
}
