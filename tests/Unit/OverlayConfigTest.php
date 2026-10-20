<?php

namespace LocalMediaProxy\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use LocalMediaProxy\Laravel\OverlayConfig;

class OverlayConfigTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'endpoint' => 'http://127.0.0.1:9100',
            'bucket' => 'laravel-dev',
            'key' => 'LMPLOCALABCDEFGHJKL2',
            'secret' => 'local-secret',
            'region' => 'us-east-1',
            'url' => null,
            'disks' => ['s3'],
            'allowed_environments' => ['local', 'testing'],
            'allowed_hosts' => [],
        ], $overrides);
    }

    public function test_safe_local_configuration_passes(): void
    {
        (new OverlayConfig($this->config(), 'local'))->assertSafe();
        (new OverlayConfig($this->config(['endpoint' => 'https://s3.test']), 'local'))->assertSafe();
        (new OverlayConfig($this->config(['endpoint' => 'http://[::1]:9100']), 'testing'))->assertSafe();
        $this->addToAssertionCount(3);
    }

    public function test_refuses_production_environment(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'production' environment");
        (new OverlayConfig($this->config(), 'production'))->assertSafe();
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_refuses_non_local_endpoints(string $endpoint): void
    {
        $this->expectException(RuntimeException::class);
        (new OverlayConfig($this->config(['endpoint' => $endpoint]), 'local'))->assertSafe();
    }

    public static function unsafeEndpoints(): array
    {
        return [
            'aws' => ['https://s3.eu-west-1.amazonaws.com'],
            'bucket host' => ['https://my-bucket.s3.amazonaws.com'],
            'lan' => ['http://192.168.1.20:9100'],
            'garbage' => ['not a url'],
            'test lookalike' => ['https://evil.test.example.com'],
        ];
    }

    public function test_refuses_real_aws_access_keys(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('real AWS access key');
        (new OverlayConfig($this->config(['key' => 'AKIAIOSFODNN7EXAMPLE']), 'local'))->assertSafe();
    }

    public function test_refuses_missing_credentials(): void
    {
        $this->expectException(RuntimeException::class);
        (new OverlayConfig($this->config(['secret' => null]), 'local'))->assertSafe();
    }

    public function test_disk_config_points_at_proxy_and_keeps_other_options(): void
    {
        $disk = (new OverlayConfig($this->config(), 'local'))->diskConfig([
            'driver' => 's3',
            'key' => 'AKIAPRODUCTIONKEY000',
            'secret' => 'production-secret',
            'endpoint' => null,
            'root' => 'tenant-1',
            'visibility' => 'public',
            'throw' => true,
            'credentials' => ['should' => 'be removed'],
        ]);

        $this->assertSame('http://127.0.0.1:9100', $disk['endpoint']);
        $this->assertSame('http://127.0.0.1:9100/laravel-dev', $disk['url']);
        $this->assertSame('LMPLOCALABCDEFGHJKL2', $disk['key']);
        $this->assertSame('local-secret', $disk['secret']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertSame('tenant-1', $disk['root']);
        $this->assertSame('public', $disk['visibility']);
        $this->assertTrue($disk['throw']);
        $this->assertArrayNotHasKey('credentials', $disk);
        $this->assertStringNotContainsString('production', json_encode($disk));
    }
}
