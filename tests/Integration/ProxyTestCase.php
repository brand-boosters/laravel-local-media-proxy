<?php

namespace LocalMediaProxy\Laravel\Tests\Integration;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use LocalMediaProxy\Laravel\LocalMediaProxyServiceProvider;

/**
 * Runs against the Local Media Proxy test harness (tests/run-laravel-tests.ps1): a fake production S3 that
 * records write attempts, behind the real proxy. Skipped when the harness is not running.
 */
abstract class ProxyTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('LOCAL_MEDIA_PROXY_HARNESS')) {
            $this->markTestSkipped('The Local Media Proxy test harness is not running (use tests/run-laravel-tests.ps1).');
        }
        parent::setUp();
        $this->harness('post', '/reset');
    }

    protected function tearDown(): void
    {
        if (getenv('LOCAL_MEDIA_PROXY_HARNESS')) {
            $this->harness('post', '/outage?on=false');
            $this->assertProductionUnchanged();
        }
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LocalMediaProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3',
            // What a Vapor app typically has: production settings that must NOT be used locally.
            'key' => 'AKIAPRODUCTIONKEY000',
            'secret' => 'production-secret-that-must-not-be-used',
            'region' => 'eu-west-1',
            'bucket' => 'vapor-production',
            'throw' => true,
        ]);
        $app['config']->set('local-media-proxy', array_merge(require __DIR__.'/../../config/local-media-proxy.php', [
            'enabled' => true,
            'endpoint' => getenv('LOCAL_MEDIA_PROXY_ENDPOINT'),
            'bucket' => getenv('LOCAL_MEDIA_PROXY_BUCKET'),
            'key' => getenv('LOCAL_MEDIA_PROXY_KEY'),
            'secret' => getenv('LOCAL_MEDIA_PROXY_SECRET'),
            'disks' => ['s3'],
        ]));
    }

    protected function harness(string $method, string $path): mixed
    {
        $url = rtrim(getenv('LOCAL_MEDIA_PROXY_HARNESS'), '/').$path;
        $context = stream_context_create(['http' => ['method' => strtoupper($method), 'ignore_errors' => true, 'header' => "Content-Length: 0\r\n"]]);
        $body = file_get_contents($url, false, $context);

        return json_decode($body ?: 'null', true);
    }

    protected function productionObject(string $key): ?string
    {
        $url = rtrim(getenv('LOCAL_MEDIA_PROXY_HARNESS'), '/').'/production/object?key='.rawurlencode($key);
        $body = @file_get_contents($url);

        return $body === false ? null : $body;
    }

    protected function assertProductionUnchanged(): void
    {
        $this->assertSame([], $this->harness('get', '/production/writes'), 'Production received write requests.');
        $this->assertTrue($this->harness('get', '/production/unchanged')['unchanged'], 'Production objects changed.');
    }

    protected function fetch(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = file_get_contents($url, false, $context);
        $status = (int) explode(' ', $http_response_header[0])[1];
        $source = null;
        foreach ($http_response_header as $header) {
            if (stripos($header, 'x-local-media-proxy-source:') === 0) {
                $source = trim(substr($header, strlen('x-local-media-proxy-source:')));
            }
        }

        return ['status' => $status, 'body' => $body, 'source' => $source];
    }
}
