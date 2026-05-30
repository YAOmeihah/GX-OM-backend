<?php

namespace App\Http\Controllers;

use App\Services\S3RuntimeConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        if (! $this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        $configService = app(S3RuntimeConfigService::class);

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
            'source' => $configService->hasRuntimeConfig() ? 'runtime' : 'env',
            's3_config' => $configService->maskedConfig(),
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
     *   "message": "配置测试失败"
     * }
     * @response 500 scenario="更新失败" {
     *   "success": false,
     *   "message": "配置更新失败"
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
        if (! $this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        try {
            $validated = $request->validate([
                'access_key' => 'nullable|string',
                'secret_key' => 'nullable|string',
                'region' => 'required|string',
                'bucket' => 'required|string',
                'endpoint' => 'required|url',
                'url' => 'nullable|url',
                'use_path_style_endpoint' => 'required|boolean',
                'verify' => 'required|boolean',
            ]);

            $configService = app(S3RuntimeConfigService::class);
            $candidateConfig = $this->buildCandidateS3Config($configService, $validated);

            if (blank($candidateConfig['key'] ?? null) || blank($candidateConfig['secret'] ?? null)) {
                return $this->errorResponse('访问密钥和秘密密钥不能为空', 422);
            }

            $testResult = $this->testS3ConnectionInternal($candidateConfig);

            if (! $testResult['success']) {
                $configService->apply();

                return $this->errorResponse('配置测试失败', 422);
            }

            $configService->persist($candidateConfig);
            $configService->apply($candidateConfig);
            $configService->commitAppliedConfig();

            return $this->successResponse(null, 'S3存储配置更新成功');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('S3配置更新失败', [
                'user_id' => auth()->id(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('配置更新失败', 500);
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
     *   "message": "配置测试失败"
     * }
     * @response 401 scenario="未认证" {
     *   "success": false,
     *   "message": "未认证用户，请先登录",
     *   "error_code": "UNAUTHENTICATED",
     *   "login_url": "http://localhost/api/login"
     * }
     */
    public function testS3Connection(Request $request)
    {
        // 只有系统管理员可以测试连接
        if (! $this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        try {
            $configService = app(S3RuntimeConfigService::class);
            $candidateConfig = null;

            if ($request->all() !== []) {
                $validated = $request->validate([
                    'access_key' => 'nullable|string',
                    'secret_key' => 'nullable|string',
                    'region' => 'required|string',
                    'bucket' => 'required|string',
                    'endpoint' => 'required|url',
                    'url' => 'nullable|url',
                    'use_path_style_endpoint' => 'required|boolean',
                    'verify' => 'required|boolean',
                ]);

                $candidateConfig = $this->buildCandidateS3Config($configService, $validated);

                if (blank($candidateConfig['key'] ?? null) || blank($candidateConfig['secret'] ?? null)) {
                    return $this->errorResponse('访问密钥和秘密密钥不能为空', 422);
                }
            }

            $effectiveConfig = $candidateConfig ?? $configService->effectiveConfig();

            if (blank($effectiveConfig['key'] ?? null) || blank($effectiveConfig['secret'] ?? null)) {
                return $this->errorResponse('访问密钥和秘密密钥不能为空', 422);
            }

            $testResult = $this->testS3ConnectionInternal($candidateConfig);
            $configService->apply();

            if (! $testResult['success']) {
                return $this->errorResponse('配置测试失败', 422);
            }

            return $this->successResponse(['status' => 'connected'], 'S3存储连接测试成功');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            app(S3RuntimeConfigService::class)->apply();

            Log::warning('S3连接测试失败', [
                'user_id' => auth()->id(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('配置测试失败', 422);
        }
    }

    public function resetS3RuntimeConfig()
    {
        if (! $this->isAdmin()) {
            return $this->errorResponse('需要系统管理员权限', 403);
        }

        app(S3RuntimeConfigService::class)->clearRuntimeConfig();
        app(S3RuntimeConfigService::class)->apply();

        return $this->successResponse(null, 'S3运行时配置已恢复为环境配置');
    }

    private function buildCandidateS3Config(S3RuntimeConfigService $configService, array $validated): array
    {
        $current = $configService->effectiveConfig();

        return $configService->effectiveConfig([
            'access_key' => filled($validated['access_key'] ?? null)
                ? $validated['access_key']
                : ($current['key'] ?? null),
            'secret_key' => filled($validated['secret_key'] ?? null)
                ? $validated['secret_key']
                : ($current['secret'] ?? null),
            'region' => $validated['region'],
            'bucket' => $validated['bucket'],
            'endpoint' => $validated['endpoint'],
            'url' => $validated['url'] ?? null,
            'use_path_style_endpoint' => (bool) $validated['use_path_style_endpoint'],
            'verify' => (bool) $validated['verify'],
        ]);
    }

    /**
     * 测试连接的内部方法
     */
    private function testS3ConnectionInternal(?array $candidateConfig = null): array
    {
        try {
            if ($candidateConfig !== null) {
                app(S3RuntimeConfigService::class)->apply($candidateConfig);
            } else {
                app(S3RuntimeConfigService::class)->apply();
            }

            $disk = Storage::disk('s3-compat');
            $disk->files('', true);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::warning('S3配置测试失败', [
                'user_id' => auth()->id(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }
}
