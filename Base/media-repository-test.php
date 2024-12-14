<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Repositories\MediaRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MediaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private MediaRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->repository = new MediaRepository(new Media());
    }

    public function test_store_media()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $metadata = [
            'title' => 'Test Image',
            'alt_text' => 'Test Alt Text',
            'type' => 'image'
        ];

        $media = $this->repository->storeMedia(['file' => $file], $metadata);

        $this->assertNotNull($media);
        $this->assertEquals('Test Image', $media->title);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_get_by_type()
    {
        Media::factory()->count(2)->create(['type' => 'image']);
        Media::factory()->create(['type' => 'document']);

        $result = $this->repository->getByType('image');

        $this->assertEquals(2, $result->total());
        $this->assertEquals('image', $result->first()->type);
    }

    public function test_get_by_folder()
    {
        $folder = MediaFolder::factory()->create();
        Media::factory()->count(3)->create(['folder_id' => $folder->id]);
        Media::factory()->create(['folder_id' => null]);

        $result = $this->repository->getByFolder($folder->id);

        $this->assertEquals(3, $result->total());
        $this->assertEquals($folder->id, $result->first()->folder_id);
    }

    public function test_move_to_folder()
    {
        $media1 = Media::factory()->create(['folder_id' => null]);
        $media2 = Media::factory()->create(['folder_id' => null]);
        $folder = MediaFolder::factory()->create();

        $result = $this->repository->moveToFolder([$media1->id, $media2->id], $folder->id);

        $this->assertTrue($result);
        $this->assertEquals($folder->id, $media1->fresh()->folder_id);
        $this->assertEquals($folder->id, $media2->fresh()->folder_id);
    }

    public function test_delete_with_file()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $media = $this->repository->storeMedia(['file' => $file], [
            'title' => 'Test',
            'type' => 'image'
        ]);

        $result = $this->repository->deleteWithFile($media->id);

        $this->assertTrue($result);
        $this->assertNull(Media::find($media->id));
        Storage::disk('public')->assertMissing($media->path);
    }

    public function test_get_media_stats()
    {
        Media::factory()->create([
            'type' => 'image',
            'size' => 1000
        ]);
        Media::factory()->create([
            'type' => 'image',
            'size' => 2000
        ]);
        Media::factory()->create([
            'type' => 'document',
            'size' => 3000
        ]);

        $stats = $this->repository->getMediaStats();

        $this->assertEquals(6000, $stats['total_size']);
        $this->assertEquals(2, $stats['by_type']['image']['count']);
        $this->assertEquals(1, $stats['by_type']['document']['count']);
    }

    public function test_advanced_search()
    {
        Media::factory()->create([
            'title' => 'Test Image',
            'type' => 'image',
            'size' => 1000
        ]);

        Media::factory()->create([
            'title' => 'Another Document',
            'type' => 'document',
            'size' => 2000
        ]);

        $filters = [
            'search' => 'Test',
            'type' => 'image',
            'size_min' => 500
        ];

        $result = $this->repository->advancedSearch($filters);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('Test Image', $result->first()->title);
    }
}
