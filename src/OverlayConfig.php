<?php

namespace LocalMediaProxy\Laravel;

use RuntimeException;

/**
 * Builds S3 disk configuration that points at the Local Media Proxy, and refuses
 * anything that could reach real AWS storage (fail closed).
 */
final class OverlayConfig
{
    public function __construct(private readonly array $config, private readonly string $environment)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Validates the overlay settings. Throws when the configuration is ambiguous or unsafe.
     */
    public function assertSafe(): void
    {
        $allowed = $this->config['allowed_environments'] ?? ['local', 'testing'];
        if (! in_array($this->environment, $allowed, true)) {
            throw new RuntimeException("Local Media Proxy is enabled in the '{$this->environment}' environment. It may only be used in: ".implode(', ', $allowed).'. Set LOCAL_MEDIA_PROXY_ENABLED=false.');
        }

        $endpoint = (string) ($this->config['endpoint'] ?? '');
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException("LOCAL_MEDIA_PROXY_ENDPOINT '{$endpoint}' is not a valid URL.");
        }
        $host = trim(strtolower($host), '[]');
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || str_ends_with($host, '.localhost');
        $herd = str_ends_with($host, '.test');
        $extra = in_array($host, array_map('strtolower', $this->config['allowed_hosts'] ?? []), true);
        if (! $loopback && ! $herd && ! $extra) {
            throw new RuntimeException("LOCAL_MEDIA_PROXY_ENDPOINT must point at the local proxy (loopback or a *.test host), not '{$host}'.");
        }

        foreach (['bucket', 'key', 'secret'] as $required) {
            if (empty($this->config[$required])) {
                throw new RuntimeException('LOCAL_MEDIA_PROXY_'.strtoupper($required).' is not set. Copy the values from Local Media Proxy > Proxy > Profiles.');
            }
        }

        if (preg_match('/^(AKIA|ASIA)[A-Z0-9]{12,}$/', (string) $this->config['key'])) {
            throw new RuntimeException('LOCAL_MEDIA_PROXY_KEY looks like a real AWS access key. Use the local proxy credentials from Local Media Proxy; production credentials belong only in the proxy.');
        }
    }

    /**
     * The S3 disk configuration for the proxy, keeping unrelated options of an existing disk.
     */
    public function diskConfig(array $existing = []): array
    {
        $endpoint = rtrim((string) $this->config['endpoint'], '/');
        $bucket = (string) $this->config['bucket'];
        $url = $this->config['url'] ?: $endpoint.'/'.$bucket;

        $disk = array_merge($existing, [
            'driver' => 's3',
            'key' => $this->config['key'],
            'secret' => $this->config['secret'],
            'token' => null,
            'region' => $this->config['region'] ?: 'us-east-1',
            'bucket' => $bucket,
            'url' => $url,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'local_media_proxy' => true,
        ]);

        // Never inherit credential providers that could resolve real AWS credentials.
        unset($disk['credentials'], $disk['profile']);

        return $disk;
    }
}
