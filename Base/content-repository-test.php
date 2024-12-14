<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Models\Content;
use App\Models\Category;
use App\Repositories\ContentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class ContentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ContentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ContentRepository(new Content());
    }

    public function test_get_published_content()
    {
        // Create test data
        Content::factory()->count(3)->create([
            'status' => 'published',
            'published_at' => now()->subDay()
        ]);
        Content::factory()->create([
            'status' => 'draft'
        ]);

        $result = $this->repository->getPublished();

        $this->assertEquals(3, $result->total());
        $this->assertEquals('published', $result->first()->status);
    }

    public function test_get_published_by_slug()
    {
        $content = Content::factory()->create([
            'status' => 'published',
            'published_at' => now()->subDay(),
            'slug' => 'test-content'
        ]);

        $result = $this->repository->getPublishedBySlug('test-content');

        $this->assertNotNull($result);
        $this->assertEquals($content->id, $result->id);
    }

    public function test_get_by_category()
    {
        $category = Category::factory()->create();
        
        Content::factory()->count(2)->create([
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now()->subDay()
        ]);

        $result = $this->repository->getByCategory($category->id);

        $this->assertEquals(2, $result->total());
        $this->assertEquals($category->id, $result->first()->category_id);
    }

    public function test_schedule_publish()
    {
        $content = Content::factory()->create([
            'status' => 'draft'
        ]);

        $publishAt = now()->addDay()->format('Y-m-d H:i:s');
        $result = $this->repository->schedulePublish($content->id, $publishAt);

        $this->assertTrue($result);
        $this->assertEquals('scheduled', $content->fresh()->status);
        $this->assertEquals($publishAt, $content->fresh()->published_at);
    }

    public function test_get_content_stats()
    {
        Content::factory()->count(2)->create(['status' => 'draft']);
        Content::factory()->count(3)->create(['status' => 'published']);
        Content::factory()->create(['status' => 'scheduled']);

        $stats = $this->repository->getContentStats();

        $this->assertEquals(2, $stats['draft']);
        $this->assertEquals(3, $stats['published']);
        $this->assertEquals(1, $stats['scheduled']);
    }

    public function test_get_related_content()
    {
        $category = Category::factory()->create();
        
        $content = Content::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now()->subDay()
        ]);

        Content::factory()->count(3)->create([
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now()->subDay()
        ]);

        $related = $this->repository->getRelated($content->id, 2);

        $this->assertEquals(2, $related->count());
        $this->assertNotEquals($content->id, $related->first()->id);
    }

    public function test_update_status()
    {
        $content = Content::factory()->create(['status' => 'draft']);

        $result = $this->repository->updateStatus($content->id, 'published');

        $this->assertTrue($result);
        $this->assertEquals('published', $content->fresh()->status);
        $this->assertNotNull($content->fresh()->published_at);
    }

    public function test_advanced_search()
    {
        Content::factory()->create([
            'title' => 'Test Title',
            'content' => 'Test Content',
            'status' => 'published'
        ]);

        $result =