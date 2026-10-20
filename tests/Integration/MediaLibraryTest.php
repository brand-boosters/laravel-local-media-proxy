<?php

namespace LocalMediaProxy\Laravel\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'products';
    protected $guarded = [];

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(120)->format('webp')->nonQueued();
        $this->addMediaConversion('card')->width(320)->nonQueued();
    }
}

/**
 * Spatie Media Library on the overlaid "s3" disk: uploads, conversions, regenerating from a production
 * original and deleting media all happen locally.
 */
class MediaLibraryTest extends ProxyTestCase
{
    protected function setUp(): void
    {
        if (! class_exists(MediaLibraryServiceProvider::class)) {
            $this->markTestSkipped('spatie/laravel-medialibrary is not installed.');
        }
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $migration = require $this->mediaMigrationPath();
        $migration->up();
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MediaLibraryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('media-library.disk_name', 's3');
        $app['config']->set('media-library.queue_conversions_by_default', false);
        $app['config']->set('media-library.image_driver', 'gd');
    }

    private function mediaMigrationPath(): string
    {
        $dir = dirname((new \ReflectionClass(MediaLibraryServiceProvider::class))->getFileName(), 2).'/database/migrations';
        $file = collect(glob($dir.'/create_media_table.php*'))->first();
        $this->assertNotNull($file, 'Media Library migration stub not found');

        return $file;
    }

    public function test_upload_with_conversions_is_stored_locally(): void
    {
        $product = Product::create(['name' => 'Chair']);
        $media = $product->addMedia(UploadedFile::fake()->image('chair.jpg', 800, 600))->toMediaCollection();

        $disk = Storage::disk('s3');
        $this->assertTrue($disk->exists($media->getPathRelativeToRoot()));
        $this->assertTrue($disk->exists($media->getPathRelativeToRoot('thumb')));
        $this->assertTrue($disk->exists($media->getPathRelativeToRoot('card')));
        $thumb = imagecreatefromstring($disk->get($media->getPathRelativeToRoot('thumb')));
        $this->assertSame(120, imagesx($thumb));

        $public = $this->fetch($media->getUrl('thumb'));
        $this->assertSame(200, $public['status']);
        $this->assertSame('local', $public['source']);
        $this->assertNull($this->productionObject($media->getPathRelativeToRoot()));
    }

    public function test_attach_a_production_image_and_regenerate_conversions(): void
    {
        $product = Product::create(['name' => 'Lamp']);
        // Simulates a media row that already exists in production, pointing at a production file.
        $media = $product->addMediaFromDisk('images/products/1/photo.jpg', 's3')
            ->preservingOriginal()
            ->toMediaCollection();

        $this->assertNotNull($this->productionObject('images/products/1/photo.jpg'), 'preservingOriginal keeps the source');
        $this->assertTrue(Storage::disk('s3')->exists($media->getPathRelativeToRoot('thumb')));

        // Regenerate after a code change to the conversions.
        $this->artisan('media-library:regenerate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Storage::disk('s3')->exists($media->getPathRelativeToRoot('card')));
    }

    public function test_deleting_media_removes_local_files_only(): void
    {
        $product = Product::create(['name' => 'Table']);
        $media = $product->addMedia(UploadedFile::fake()->image('table.png', 400, 400))->toMediaCollection();
        $path = $media->getPathRelativeToRoot();
        $media->delete();

        $this->assertFalse(Storage::disk('s3')->exists($path));
        $this->assertFalse(Storage::disk('s3')->exists(dirname($path).'/conversions/'.pathinfo($path, PATHINFO_FILENAME).'-thumb.webp'));
    }
}
