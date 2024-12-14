<?php

namespace Tests\Unit\Repositories;

use App\Core\Repositories\MediaRepository;
use App\Models\Media;
use App\Exceptions\MediaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected MediaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->repository = new MediaRepository(new Media());
    }

    public function test_can_store_file()
    {
        $file = UploadedFile::fake()->image('test.jpg');

        $media = $this->repository->storeFile($file, [
            'alt' => 'Test Image'
        ]);

        $this->assertInstanceOf(Media::class, $media);
        $this->assertEquals('test.jpg', $media->name);
        $this->assertStringStartsWith('media/', $media->path);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_can_store_from_url()
    {
        // Mock external URL
        $url = 'https://example.com/image.jpg';
        $testImage = UploadedFile::fake()->image('image.jpg');
        
        // Mock file_get_contents
        $this->mock('alias:file_get_contents', function () use ($testImage) {
            return file_get_contents($testImage->path());
        });

        $media = $this->repository->storeFromUrl($url);

        $this->assertInstanceOf(Media::class, $media);
        $this->assertEquals('image.jpg', $media->name);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_can_generate_thumbnails()
    {
        $file = UploadedFile::fake()->image('test.jpg', 1000, 1000);
        $media = $this->repository->storeFile($file);

        $media = $this->repository->generateThumbnails($media->id, [
            'small' => [200, 200],
            'medium' => [400, 400]
        ]);

        $metadata = json_decode($media->metadata, true);
        $this->assertArrayHasKey('thumbnails', $metadata);
        $this->assertArrayHasKey('small', $metadata['thumbnails']);
        $this->assertArrayHasKey('medium', $metadata['thumbnails']);

        Storage::disk('public')->assertExists($metadata['thumbnails']['small']);
        Storage::disk('public')->assertExists($metadata['thumbnails']['medium']);
    }

    public function test_can_update_metadata()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $media = $this->repository->storeFile($file, ['alt' => 'Original Alt']);

        $media = $this->repository->updateMetadata($media->id, [
            'title' => 'Test Title',
            'alt' => 'Updated Alt'
        ]);

        $metadata = json_decode($media->metadata, true);
        $this->assertEquals('Test Title', $metadata['title']);
        $this->assertEquals('Updated Alt', $metadata['alt']);
    }

    public function test_can_move_to_folder()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $media = $this->repository->storeFile($file);
        $originalPath = $media->path;

        $media = $this->repository->moveToFolder($media->id, 'new-folder');

        $this->assertStringStartsWith('new-folder/', $media->path);
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_can_find_by_hash()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $media = $this->repository->storeFile($file);

        $found = $this->repository->findByHash($media->hash);

        $this->assertInstanceOf(Media::class, $found);
        $this->assertEquals($media->id, $found->id);
    }

    public function test_prevents_duplicate_files()
    {
        $file1 = UploadedFile::fake()->image('test1.jpg');
        $file2 = UploadedFile::fake()->image('test2.jpg', 100, 100);
        
        // Create identical content
        file_put_contents($file2->path(), file_get_contents($file1->path()));

        $media1 = $this->repository->storeFile($file1);
        $media2 = $this->repository->storeFile($file2);

        $this->assertEquals($media1->id, $media2->id);
    }
}
