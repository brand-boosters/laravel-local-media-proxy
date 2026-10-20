<?php

namespace LocalMediaProxy\Laravel\Tests\Integration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

class StorageThroughProxyTest extends ProxyTestCase
{
    public function test_the_s3_disk_is_repointed_at_the_proxy(): void
    {
        $config = config('filesystems.disks.s3');
        $this->assertSame(getenv('LOCAL_MEDIA_PROXY_ENDPOINT'), $config['endpoint']);
        $this->assertSame(getenv('LOCAL_MEDIA_PROXY_KEY'), $config['key']);
        $this->assertStringNotContainsString('production-secret', json_encode($config));
        $this->artisan('local-media-proxy:status')->assertSuccessful();
    }

    public function test_s3_disks_that_bypass_the_proxy_are_refused(): void
    {
        config(['filesystems.disks.archive' => [
            'driver' => 's3', 'key' => 'AKIAPRODUCTIONKEY000', 'secret' => 'x', 'region' => 'eu-west-1', 'bucket' => 'vapor-archive',
        ]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not routed through the proxy');
        Storage::disk('archive')->exists('anything');
    }

    public function test_config_replaced_at_runtime_is_still_routed_through_the_proxy(): void
    {
        config(['filesystems.disks.s3' => [
            'driver' => 's3', 'key' => 'AKIAPRODUCTIONKEY000', 'secret' => 'x', 'region' => 'eu-west-1', 'bucket' => 'vapor-production',
        ]]);
        Storage::forgetDisk('s3');
        Storage::disk('s3')->put('runtime/config.txt', 'local');

        $this->assertSame('local', Storage::disk('s3')->get('runtime/config.txt'));
        $this->assertNull($this->productionObject('runtime/config.txt'));
        $this->assertStringStartsWith(getenv('LOCAL_MEDIA_PROXY_ENDPOINT'), Storage::disk('s3')->temporaryUrl('runtime/config.txt', now()->addMinute()));
    }

    public function test_reads_production_objects(): void
    {
        $disk = Storage::disk('s3');
        $this->assertTrue($disk->exists('documents/terms.txt'));
        $this->assertSame('Production terms of service', $disk->get('documents/terms.txt'));
        $this->assertSame(27, $disk->size('documents/terms.txt'));
        $this->assertSame('text/plain', $disk->mimeType('documents/terms.txt'));
        $this->assertGreaterThan(0, $disk->lastModified('documents/terms.txt'));
        $this->assertFalse($disk->exists('documents/missing.txt'));
        $this->assertTrue($disk->directoryExists('images'));
        $this->assertContains('images/products/1/photo.jpg', $disk->allFiles('images'));
    }

    public function test_write_operations_stay_local(): void
    {
        $disk = Storage::disk('s3');
        $disk->put('notes/new.txt', 'hello');
        $this->assertSame('hello', $disk->get('notes/new.txt'));
        $this->assertNull($this->productionObject('notes/new.txt'));

        $disk->copy('documents/terms.txt', 'notes/terms-copy.txt');
        $disk->move('notes/new.txt', 'notes/moved.txt');
        $this->assertFalse($disk->exists('notes/new.txt'));
        $this->assertSame('hello', $disk->get('notes/moved.txt'));
        $this->assertSame('Production terms of service', $disk->get('notes/terms-copy.txt'));
        $this->assertEqualsCanonicalizing(['notes/moved.txt', 'notes/terms-copy.txt'], $disk->files('notes'));

        $disk->makeDirectory('empty-dir');
        $this->assertContains('empty-dir', $disk->directories());
        $disk->deleteDirectory('notes');
        $this->assertSame([], $disk->files('notes'));
    }

    public function test_replace_a_production_image_and_reset_it(): void
    {
        $disk = Storage::disk('s3');
        $key = 'images/products/1/photo.jpg';
        $original = $this->productionObject($key);
        $this->assertNotNull($original);

        $replacement = UploadedFile::fake()->image('replacement.png', 64, 64);
        $disk->putFileAs('images/products/1', $replacement, 'photo.jpg');

        // The local site sees the new image, through Storage and through the public URL.
        $this->assertSame(file_get_contents($replacement->getRealPath()), $disk->get($key));
        $public = $this->fetch($disk->url($key));
        $this->assertSame(200, $public['status']);
        $this->assertSame('local', $public['source']);
        $this->assertSame(file_get_contents($replacement->getRealPath()), $public['body']);

        // Production is unchanged.
        $this->assertSame($original, $this->productionObject($key));

        // Reset the override: the production image is visible again.
        $this->harness('post', '/revert?key='.rawurlencode($key));
        $this->assertSame($original, $disk->get($key));
    }

    public function test_delete_a_production_image_locally(): void
    {
        $disk = Storage::disk('s3');
        $key = 'images/products/1/photo.jpg';
        $this->assertTrue($disk->delete($key));
        $this->assertFalse($disk->exists($key));
        $this->assertNotContains($key, $disk->allFiles('images'));
        $this->assertSame(404, $this->fetch($disk->url($key))['status']);
        $this->assertNotNull($this->productionObject($key), 'The production object must still exist.');

        $changes = collect($this->harness('get', '/changes'));
        $this->assertSame('Deleted', $changes->firstWhere('key', $key)['change']);

        $this->expectException(UnableToReadFile::class);
        $disk->get($key);
    }

    public function test_visibility_and_metadata(): void
    {
        $disk = Storage::disk('s3');
        $disk->put('public/banner.txt', 'banner', ['visibility' => 'public', 'CacheControl' => 'max-age=600']);
        $this->assertSame('public', $disk->getVisibility('public/banner.txt'));
        $disk->setVisibility('public/banner.txt', 'private');
        $this->assertSame('private', $disk->getVisibility('public/banner.txt'));

        // Changing visibility of a production object is a local metadata override.
        $disk->setVisibility('documents/terms.txt', 'public');
        $this->assertSame('public', $disk->getVisibility('documents/terms.txt'));
        $changes = collect($this->harness('get', '/changes'));
        $this->assertSame('MetadataChanged', $changes->firstWhere('key', 'documents/terms.txt')['change']);
    }

    public function test_temporary_urls_are_signed_for_the_proxy(): void
    {
        $disk = Storage::disk('s3');
        $url = $disk->temporaryUrl('documents/terms.txt', now()->addMinutes(5));
        $this->assertStringStartsWith(getenv('LOCAL_MEDIA_PROXY_ENDPOINT'), $url);
        $response = $this->fetch($url);
        $this->assertSame(200, $response['status']);
        $this->assertSame('Production terms of service', $response['body']);

        $tampered = str_replace('documents/terms.txt', 'documents/other.txt', $url);
        $this->assertSame(403, $this->fetch($tampered)['status']);
    }

    public function test_large_stream_upload_uses_multipart_locally(): void
    {
        $disk = Storage::disk('s3');
        $path = tempnam(sys_get_temp_dir(), 's3ov');
        $handle = fopen($path, 'w+b');
        for ($i = 0; $i < 20; $i++) {
            fwrite($handle, random_bytes(1024 * 1024));
        }
        rewind($handle);
        $hash = hash_file('sha256', $path);

        $disk->writeStream('videos/large.bin', $handle);
        fclose($handle);
        unlink($path);

        $this->assertSame(20 * 1024 * 1024, $disk->size('videos/large.bin'));
        $this->assertSame($hash, hash('sha256', $disk->get('videos/large.bin')));
    }

    public function test_image_conversions_with_gd(): void
    {
        $disk = Storage::disk('s3');
        $source = imagecreatefromstring($disk->get('images/products/1/photo.jpg'));
        $this->assertNotFalse($source);

        foreach ([['thumb', 120], ['medium', 320]] as [$name, $width]) {
            $resized = imagescale($source, $width);
            ob_start();
            imagewebp($resized, null, 80);
            $disk->put("images/products/1/conversions/photo-{$name}.webp", ob_get_clean(), ['ContentType' => 'image/webp']);
        }

        $thumb = imagecreatefromstring($disk->get('images/products/1/conversions/photo-thumb.webp'));
        $this->assertSame(120, imagesx($thumb));
        $this->assertSame('image/webp', $disk->mimeType('images/products/1/conversions/photo-medium.webp'));
        $this->assertNull($this->productionObject('images/products/1/conversions/photo-thumb.webp'));
    }

    public function test_local_storage_keeps_working_during_a_production_outage(): void
    {
        $disk = Storage::disk('s3');
        $disk->put('outage/before.txt', 'before');
        $this->harness('post', '/clear-cache');
        $this->harness('post', '/outage?on=true');

        $this->assertSame('before', $disk->get('outage/before.txt'));
        $disk->put('outage/during.txt', 'during');
        $this->assertSame('during', $disk->get('outage/during.txt'));
        $disk->delete('outage/during.txt');

        // Production reads report the outage instead of returning stale or wrong data.
        $this->expectException(UnableToReadFile::class);
        $disk->get('documents/terms.txt');
    }
}
