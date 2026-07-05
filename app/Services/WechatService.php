<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WechatService
{
    private string $appId;

    private string $appSecret;

    public function __construct()
    {
        $this->appId = config('wechat.mini_app_id');
        $this->appSecret = config('wechat.mini_app_secret');
    }

    /**
     * 获取小程序 access_token（带缓存）
     */
    public function getAccessToken(): ?string
    {
        $cacheKey = 'wechat:mini:access_token';

        return Cache::remember($cacheKey, 7000, function () {
            $response = Http::get('https://api.weixin.qq.com/cgi-bin/token', [
                'grant_type' => 'client_credential',
                'appid' => $this->appId,
                'secret' => $this->appSecret,
            ]);

            $data = $response->json();

            if (isset($data['errcode']) && $data['errcode'] !== 0) {
                Log::error('WeChat access_token 获取失败', $data);

                return null;
            }

            return $data['access_token'] ?? null;
        });
    }

    /**
     * 检查微信配置是否完整
     */
    public function isConfigured(): bool
    {
        return ! empty($this->appId) && ! empty($this->appSecret);
    }

    /**
     * 获取不限制的小程序码
     * 文档: https://developers.weixin.qq.com/miniprogram/dev/OpenApiDoc/qrcode-link/unlimited-qrcode/getUnlimitedQRCode.html
     *
     * @param  string  $scene  最大32个可见字符
     * @param  string  $page  必须是已经发布的小程序存在的页面（否则报错），例如 pages/index/index
     * @param  int  $width  二维码的宽度，单位 px，最小 280px，最大 1280px
     * @return string|null 图片二进制数据
     */
    public function getUnlimitedQRCode(string $scene, string $page = '', int $width = 430): ?string
    {
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return null;
        }

        $url = "https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token={$accessToken}";

        $params = [
            'scene' => $scene,
            'width' => $width,
            'check_path' => false, // 暂不校验路径，避免未发布时报错（但实际发布后建议校验）
            'env_version' => config('app.env') === 'production' ? 'release' : 'trial', // 根据环境选择版本
        ];

        if (! empty($page)) {
            $params['page'] = $page;
        }

        try {
            $response = Http::post($url, $params);

            // 微信返回的如果是 JSON 且包含 errcode，说明出错了
            if (str_contains($response->header('Content-Type'), 'application/json')) {
                $json = $response->json();
                if (isset($json['errcode']) && $json['errcode'] !== 0) {
                    Log::error('WeChat getUnlimitedQRCode failed', $json);

                    return null;
                }
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::error('WeChat getUnlimitedQRCode request failed: '.$e->getMessage());

            return null;
        }
    }
}
