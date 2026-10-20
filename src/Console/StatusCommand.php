<?php

namespace LocalMediaProxy\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use LocalMediaProxy\Laravel\OverlayConfig;
use Throwable;

class StatusCommand extends Command
{
    protected $signature = 'local-media-proxy:status {--disk= : Disk to check (default: the first overlay disk)}';

    protected $description = 'Check that the Local Media Proxy is reachable and the disks point at it';

    /** The command name from before the rename (S3 Overlay). */
    protected $aliases = ['s3-overlay:status'];

    public function handle(OverlayConfig $overlay): int
    {
        $config = config('local-media-proxy');
        $this->line('Overlay enabled: '.($overlay->enabled() ? '<info>yes</info>' : '<comment>no</comment> (LOCAL_MEDIA_PROXY_ENABLED)'));
        $this->line('Endpoint: '.$config['endpoint']);
        $this->line('Bucket: '.($config['bucket'] ?? '(not set)'));
        $this->line('Access key: '.($config['key'] ? substr($config['key'], 0, 9).'…' : '(not set)'));

        try {
            $overlay->assertSafe();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $health = @file_get_contents(rtrim($config['endpoint'], '/').'/_overlay/health', false, stream_context_create(['http' => ['timeout' => 3]]));
        if ($health === false) {
            $this->error('The proxy is not reachable. Start it from the Local Media Proxy app or run: LocalMediaProxy.Proxy.exe start');

            return self::FAILURE;
        }
        $this->info('Proxy: '.$health);

        $disks = collect(config('filesystems.disks'))->filter(fn ($d) => ($d['local_media_proxy'] ?? $d['s3_overlay'] ?? false)
            || in_array($d['driver'] ?? null, ['local-media-proxy', 's3-overlay'], true))->keys();
        $this->line('Overlay disks: '.($disks->isEmpty() ? '(none)' : $disks->implode(', ')));

        $disk = $this->option('disk') ?: $disks->first();
        if ($disk) {
            try {
                $files = Storage::disk($disk)->files('', false);
                $this->info("Listing '{$disk}' through the proxy works (".count($files).' objects at the root).');
            } catch (Throwable $e) {
                $this->error("Listing '{$disk}' failed: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
