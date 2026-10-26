<?php

namespace LocalMediaProxy\Laravel;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use LocalMediaProxy\Laravel\Console\StatusCommand;

/**
 * Routes S3 disks through the local proxy.
 *
 * The decision is made when a disk is created, from the live configuration, so config changes made after
 * this provider registered cannot silently send requests to real S3. While the overlay is enabled:
 *  - disks listed in local-media-proxy.disks are re-pointed at the proxy (other options are kept);
 *  - any other disk using the "s3" driver is refused (strict mode, the default).
 */
class LocalMediaProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/local-media-proxy.php', 'local-media-proxy');

        // A config/s3-overlay.php published before the rename (S3 Overlay) still applies.
        if (is_array($legacy = $this->app['config']->get('s3-overlay'))) {
            $this->app['config']->set('local-media-proxy', array_merge($this->app['config']->get('local-media-proxy', []), $legacy));
        }

        // Not a singleton: always built from the current configuration.
        $this->app->bind(OverlayConfig::class, fn ($app) => new OverlayConfig(
            $app['config']->get('local-media-proxy', []),
            (string) $app->environment()
        ));

        // Also rewrite the config itself, for code that reads config('filesystems.disks.s3.url') directly.
        $this->repointConfig();
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/local-media-proxy.php' => config_path('local-media-proxy.php')], 'local-media-proxy-config');
        $this->repointConfig();

        // The drivers are method references, not anonymous closures: Laravel 13 rebinds anonymous extend()
        // callbacks to the filesystem manager, so $this inside them would no longer be this provider.
        // Explicit driver: 'my-disk' => ['driver' => 'local-media-proxy'] in config/filesystems.php.
        // 's3-overlay' is the driver name from before the rename.
        foreach (['local-media-proxy', 's3-overlay'] as $driver) {
            Storage::extend($driver, $this->createProxyDriver(...));
        }

        Storage::extend('s3', $this->createS3Driver(...));

        if ($this->app->runningInConsole()) {
            $this->commands([StatusCommand::class]);
        }
    }

    /** The 'local-media-proxy' (and old 's3-overlay') driver: always through the proxy. */
    private function createProxyDriver($app, array $config)
    {
        $overlay = $app->make(OverlayConfig::class);
        $overlay->assertSafe();

        return $this->createChecked($overlay->diskConfig($config));
    }

    /** The 's3' driver: through the proxy when enabled for the disk, refused or real S3 otherwise. */
    private function createS3Driver($app, array $config)
    {
        return $this->createS3Disk($config);
    }

    private function createS3Disk(array $config)
    {
        $overlay = $this->app->make(OverlayConfig::class);
        if (! $overlay->enabled()) {
            return $this->manager()->createS3Driver($config);
        }
        $overlay->assertSafe();

        if (! ($config['local_media_proxy'] ?? $config['s3_overlay'] ?? false)) {
            $name = array_search($config, $this->app['config']->get('filesystems.disks', []), true);
            if (is_string($name) && in_array($name, $this->overlayDisks(), true)) {
                $config = $overlay->diskConfig($config);
            } elseif ($this->app['config']->get('local-media-proxy.strict', true)) {
                $label = is_string($name) ? "'{$name}'" : 'an s3 disk';
                $bucket = $config['bucket'] ?? '?';
                throw new RuntimeException(
                    "Local Media Proxy is enabled, but {$label} (bucket '{$bucket}') is not routed through the proxy. "
                    .'Refusing to connect to real S3 from a local environment. Add the disk to LOCAL_MEDIA_PROXY_DISKS.'
                );
            } else {
                return $this->manager()->createS3Driver($config);
            }
        }

        return $this->createChecked($config);
    }

    /** Creates the disk after verifying that it targets the local proxy. */
    private function createChecked(array $config)
    {
        $host = trim(strtolower((string) parse_url((string) ($config['endpoint'] ?? ''), PHP_URL_HOST)), '[]');
        $allowed = array_map('strtolower', $this->app['config']->get('local-media-proxy.allowed_hosts', []));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost') && ! in_array($host, $allowed, true)) {
            throw new RuntimeException("A Local Media Proxy disk points at '{$host}' instead of the local proxy.");
        }
        if (empty($config['use_path_style_endpoint'])) {
            throw new RuntimeException('Local Media Proxy disks must use path-style addressing.');
        }

        return $this->manager()->createS3Driver($config);
    }

    private function repointConfig(): void
    {
        $overlay = $this->app->make(OverlayConfig::class);
        if (! $overlay->enabled()) {
            return;
        }
        $overlay->assertSafe();

        $config = $this->app['config'];
        foreach ($this->overlayDisks() as $disk) {
            $existing = $config->get("filesystems.disks.{$disk}");
            if (! is_array($existing) || ($existing['local_media_proxy'] ?? $existing['s3_overlay'] ?? false)) {
                continue;
            }
            $config->set("filesystems.disks.{$disk}", $overlay->diskConfig($existing));
            if ($this->app->resolved('filesystem')) {
                $this->manager()->forgetDisk($disk);
            }
        }
    }

    /** @return list<string> */
    private function overlayDisks(): array
    {
        return array_values((array) $this->app['config']->get('local-media-proxy.disks', []));
    }

    private function manager(): FilesystemManager
    {
        return $this->app->make('filesystem');
    }
}
