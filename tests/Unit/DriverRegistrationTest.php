<?php

namespace LocalMediaProxy\Laravel\Tests\Unit;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use LocalMediaProxy\Laravel\LocalMediaProxyServiceProvider;
use Orchestra\Testbench\TestCase;
use ReflectionFunction;
use ReflectionProperty;

/**
 * Laravel 13 rebinds anonymous Storage::extend() callbacks to the filesystem manager ($this becomes the manager).
 * The drivers must keep working then, so they are method references; this test does the same rebinding on any
 * Laravel version and builds every driver (no request is sent).
 */
class DriverRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LocalMediaProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('local-media-proxy', [
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
            'strict' => true,
        ]);
        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3', 'key' => 'AKIAREALPRODKEY00000', 'secret' => 'prod', 'region' => 'eu-west-1', 'bucket' => 'production-bucket',
        ]);
    }

    public function test_drivers_work_when_laravel_rebinds_the_callbacks_to_the_manager(): void
    {
        $manager = $this->app->make('filesystem');
        $creators = (new ReflectionProperty(FilesystemManager::class, 'customCreators'))->getValue($manager);

        foreach (['s3', 'local-media-proxy', 's3-overlay'] as $driver) {
            $this->assertArrayHasKey($driver, $creators);
            $creator = $creators[$driver];
            // What Laravel 13 does (RebindsCallbacksToSelf): anonymous closures get the manager as $this.
            if ((new ReflectionFunction($creator))->isAnonymous()) {
                $creator = Closure::bind($creator, $manager, FilesystemManager::class);
            }
            $disk = $creator($this->app, $this->app['config']->get('filesystems.disks.s3'));
            $this->assertInstanceOf(FilesystemAdapter::class, $disk, $driver);
        }

        // And through Laravel itself: the s3 disk is routed to the proxy.
        $this->assertSame('laravel-dev', $this->app['config']->get('filesystems.disks.s3.bucket'));
        $this->assertInstanceOf(FilesystemAdapter::class, $this->app['filesystem']->disk('s3'));
    }
}
