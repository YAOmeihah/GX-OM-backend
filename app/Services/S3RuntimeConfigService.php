<?php

namespace App\Services;

use App\Models\RuntimeConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class S3RuntimeConfigService
{
    private const CONFIG_KEY = 's3-compat';

    private static ?array $previousAppliedConfig = null;

    public function effectiveConfig(array $overrides = []): array
    {
        $config = config('filesystems.disks.'.self::CONFIG_KEY, []);

        if ($runtimeConfig = $this->storedConfig()) {
            $config = $this->mergeRuntimeConfig($config, $runtimeConfig);
        }

        return $this->mergeRuntimeConfig($config, $overrides);
    }

    public function persist(array $config): RuntimeConfig
    {
        return RuntimeConfig::updateOrCreate(
            ['key' => self::CONFIG_KEY],
            ['value' => [
                'access_key' => $config['access_key'] ?? $config['key'] ?? null,
                'secret_key' => $config['secret_key'] ?? $config['secret'] ?? null,
                'region' => $config['region'] ?? null,
                'bucket' => $config['bucket'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'url' => $config['url'] ?? null,
                'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
                'verify' => (bool) ($config['verify'] ?? true),
            ]]
        );
    }

    public function hasRuntimeConfig(): bool
    {
        return $this->storedConfig() !== null;
    }

    public function clearRuntimeConfig(): void
    {
        if (! Schema::hasTable('runtime_configs')) {
            return;
        }

        RuntimeConfig::where('key', self::CONFIG_KEY)->delete();
        Storage::forgetDisk(self::CONFIG_KEY);
    }

    public function apply(?array $config = null): array
    {
        if ($config !== null) {
            self::$previousAppliedConfig ??= $this->currentManagedConfig();
            $effectiveConfig = $this->mergeRuntimeConfig($this->effectiveConfig(), $config);
        } elseif (self::$previousAppliedConfig !== null) {
            $effectiveConfig = self::$previousAppliedConfig;
            self::$previousAppliedConfig = null;
        } else {
            $effectiveConfig = $this->effectiveConfig();
        }

        return $this->applyResolvedConfig($effectiveConfig);
    }

    public function commitAppliedConfig(): void
    {
        self::$previousAppliedConfig = null;
    }

    private function applyResolvedConfig(array $effectiveConfig): array
    {
        foreach (['key', 'secret', 'region', 'bucket', 'endpoint', 'url', 'use_path_style_endpoint'] as $key) {
            Config::set('filesystems.disks.'.self::CONFIG_KEY.'.'.$key, $effectiveConfig[$key] ?? null);
        }

        $verify = $this->resolveSslVerification($effectiveConfig);
        Config::set('filesystems.disks.'.self::CONFIG_KEY.'.http.verify', $verify);
        Config::set('filesystems.disks.'.self::CONFIG_KEY.'.options.http.verify', $verify);

        Storage::forgetDisk(self::CONFIG_KEY);

        return $effectiveConfig;
    }

    private function currentManagedConfig(): array
    {
        $config = config('filesystems.disks.'.self::CONFIG_KEY, []);

        return [
            'key' => $config['key'] ?? null,
            'secret' => $config['secret'] ?? null,
            'region' => $config['region'] ?? null,
            'bucket' => $config['bucket'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            'url' => $config['url'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'verify' => $this->resolveSslVerification($config),
        ];
    }

    public function maskedConfig(): array
    {
        $config = $this->effectiveConfig();

        return [
            'region' => $config['region'] ?? null,
            'bucket' => $config['bucket'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            'url' => $config['url'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'verify' => $this->resolveSslVerification($config),
            'access_key' => ! empty($config['key']) ? '已配置' : '未配置',
            'secret_key' => ! empty($config['secret']) ? '已配置' : '未配置',
        ];
    }

    public function s3ClientOptions(?array $config = null): array
    {
        $effectiveConfig = $config
            ? $this->mergeRuntimeConfig($this->effectiveConfig(), $config)
            : $this->effectiveConfig();
        $endpoint = $effectiveConfig['endpoint'] ?? '';

        if ($endpoint !== '' && ! str_starts_with($endpoint, 'http://') && ! str_starts_with($endpoint, 'https://')) {
            $endpoint = 'https://'.$endpoint;
        }

        return [
            'credentials' => [
                'key' => $effectiveConfig['key'] ?? null,
                'secret' => $effectiveConfig['secret'] ?? null,
            ],
            'use_path_style_endpoint' => $effectiveConfig['use_path_style_endpoint'] ?? false,
            'use_aws_shared_config_files' => false,
            'endpoint' => $endpoint,
            'signature_version' => 'v4',
            'version' => 'latest',
            'region' => $effectiveConfig['region'] ?? 'us-east-1',
            'http' => [
                'verify' => $this->resolveSslVerification($effectiveConfig),
            ],
        ];
    }

    private function resolveSslVerification(array $config): bool|string
    {
        if (array_key_exists('verify', $config)) {
            return $config['verify'] ?? true;
        }

        if (isset($config['http']) && is_array($config['http']) && array_key_exists('verify', $config['http'])) {
            return $config['http']['verify'] ?? true;
        }

        if (isset($config['options']['http']) && is_array($config['options']['http']) && array_key_exists('verify', $config['options']['http'])) {
            return $config['options']['http']['verify'] ?? true;
        }

        return true;
    }

    private function storedConfig(): ?array
    {
        try {
            if (! Schema::hasTable('runtime_configs')) {
                return null;
            }

            return RuntimeConfig::where('key', self::CONFIG_KEY)->first()?->value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function mergeRuntimeConfig(array $config, array $runtimeConfig): array
    {
        if (array_key_exists('access_key', $runtimeConfig)) {
            $config['key'] = $runtimeConfig['access_key'];
        }

        if (array_key_exists('secret_key', $runtimeConfig)) {
            $config['secret'] = $runtimeConfig['secret_key'];
        }

        foreach (['key', 'secret', 'region', 'bucket', 'endpoint', 'url', 'use_path_style_endpoint', 'verify'] as $key) {
            if (array_key_exists($key, $runtimeConfig)) {
                $config[$key] = $runtimeConfig[$key];
            }
        }

        return $config;
    }
}
