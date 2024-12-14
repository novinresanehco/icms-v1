<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Repositories\{ContentRepository, CategoryRepository, MediaRepository};
use App\Models\{Content, Category, Media};
use App\Core\Database\Performance\DatabasePerformanceManager;
use App\Core\Services\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Cache, Storage, Event};
use Mockery;

class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $performanceManager;
    protected $imageProcessor;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->performanceManager = Mockery::mock(DatabasePerformanceManager::class);
        $this->performanceManager->shouldReceive('monitorQueryPerformance')->byDefault();
        
        $this->imageProcessor = Mockery::mock(ImageProcessor::class);
        
        // Disable events for clean testing
        Event::fake();
    }

    /** @test */
    public function content_repository_can_create_and_retrieve_content()
    {
        $repository = new ContentRepository($this->performanceManager);
        
        $attributes = [
            'title' => 'Test Content',
            'content' => 'Test content body',
            'status' => 'draft',
            'author_id' => 1
        ];

        // Test creation
        $content = $repository->create($attributes);
        $this->assertInstanceOf(Content::class, $content);
        $this->assertEquals('Test Content', $content->title);

        // Test retrieval
        $found = $repository->find($content->id);
        $this->assertEquals($content->id, $found->id);
        
        // Test cache
        $this->assertTrue(
            Cache::tags(['content'])->has(
                $repository->getCacheKey('find', ['id' => $content->id])
            )
        );
    }

    /** @test */
    public function category_repository_can_manage_hierarchical_structure()
    {
        $repository = new CategoryRepository($this->performanceManager);

        // Create parent category
        $parent = $repository->create([
            'name' => 'Parent Category',
            'slug' => 'parent-category'
        ]);

        // Create child category
        $child = $repository->create([
            'name' => 'Child Category',
            'slug' => 'child-category',
            'parent_id' => $parent->id
        ]);

        // Test tree structure
        $tree = $repository->getTree();
        $this->assertEquals(1, $tree->count());
        $this->assertEquals('Parent Category', $tree->first()->name);
        $this->assertEquals(1, $tree->first()->children->count());

        // Test moving categories
        $repository->moveToParent($child->id, null);
        $this->assertNull($child->fresh()->parent_id);
    }

    /** @test */
    public function media_repository_can_handle_file_uploads()
    {
        Storage::fake('public');
        
        $this->imageProcessor->shouldReceive('getImageInfo')
            ->andReturn(['width' => 800, 'height' => 600]);
        
        $this->imageProcessor->shouldReceive('processImage')
            ->andReturn(true);

        $repository = new MediaRepository($this->performanceManager, $this->imageProcessor);

        $file = UploadedFile::fake()->image('test.jpg', 800, 600);
        
        $media = $repository->upload($file, [
            'title' => 'Test Image',
            'type' => 'images'
        ]);

        // Test file storage
        Storage::disk('public')->assertExists($media->path);

        // Test metadata
        $this->assertEquals('test.jpg', $media->original_filename);
        $this->assertEquals('image/jpeg', $media->mime_type);
        $this->assertArrayHasKey('width', $media->metadata);
        $this->assertEquals(800, $media->metadata['width']);
    }

    /** @test */
    public function repositories_properly_handle_cache_invalidation()
    {
        $repository = new ContentRepository($this->performanceManager);
        
        // Create and cache content
        $content = $repository->create([
            'title' => 'Cache Test',
            'content' => 'Testing cache invalidation'
        ]);
        
        $cacheKey = $repository->getCacheKey('find', ['id' => $content->id]);
        
        // Verify cache exists
        $this->assertTrue(Cache::tags(['content'])->has($cacheKey));
        
        // Update content
        $repository->update($content->id, ['title' => 'Updated Title']);
        
        // Verify cache was invalidated
        $this->assertFalse(Cache::tags(['content'])->has($cacheKey));
    }

    /** @test */
    public function repositories_handle_exceptions_properly()
    {
        $repository = new ContentRepository($this->performanceManager);
        
        $this->expectException(\App\Core\Exceptions\RepositoryException::class);
        
        // Try to find non-existent content
        $repository->findOrFail(99999);
    }

    /** @test */
    public function repositories_respect_search_parameters()
    {
        $repository = new ContentRepository($this->performanceManager);
        
        // Create test content
        $repository->create([
            'title' => 'Searchable Title',
            'content' => 'Searchable content'
        ]);
        
        $repository->create([
            'title' => 'Different Title',
            'content' => 'Different content'
        ]);

        // Test search
        $results = $repository->search('Searchable');
        $this->assertEquals(1, $results->count());
        $this->assertEquals('Searchable Title', $results->first()->title);
    }

    /** @test */
    public function repositories_handle_relationships_correctly()
    {
        $contentRepo = new ContentRepository($this->performanceManager);
        $categoryRepo = new CategoryRepository($this->performanceManager);

        // Create category
        $category = $categoryRepo->create([
            'name' => 'Test Category',
            'slug' => 'test-category'
        ]);

        // Create content with category
        $content = $contentRepo->create([
            'title' => 'Related Content',
            'content' => 'Testing relationships',
            'category_id' => $category->id
        ]);

        // Test relationship loading
        $found = $contentRepo->with(['category'])->find($content->id);
        $this->assertTrue($found->relationLoaded('category'));
        $this->assertEquals($category->id, $found->category->id);
    }
}
