<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WechatService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatServiceTest extends TestCase
{
    public function test_unlimited_qr_code_uses_release_environment_when_configured_app_env_is_production(): void
    {
        Cache::flush();
        config([
            'app.env' => 'production',
            'wechat.mini_app_id' => 'wx-test-app-id',
            'wechat.mini_app_secret' => 'wx-test-secret',
        ]);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'wechat-access-token',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/wxa/getwxacodeunlimit*' => Http::response('fake-image', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        (new WechatService)->getUnlimitedQRCode('share-token', 'pages/bill/index');

        Http::assertSent(
            fn (Request $request): bool => str_starts_with($request->url(), 'https://api.weixin.qq.com/wxa/getwxacodeunlimit')
                && ($request->data()['env_version'] ?? null) === 'release'
        );
    }
}
