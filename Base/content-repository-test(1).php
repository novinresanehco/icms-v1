<?php

namespace Tests\Unit\Repositories;

use App\Core\Repositories\ContentRepository;
use App\Models\Content;
use App\Models\User;
use App\Models\Category;
use App\Models\Tag;
use App\Exceptions\ContentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected ContentRepository $repository;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->repository = new ContentRepository(new Content());
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_can_find_content_by_slug()
    {
        $content = Content::factory()->create([
            'slug' => 'test-content'
        ]);

        $found = $this->repository->findBySlug('test-content');

        $this->assertNotNull($found);
        $this->assertEquals($content->id, $found->id);
    }

    public function test_can_get_published_content()
    {
        Content::factory()->count(5)->create([
            'status' => 'published',
            'published_at' => now()->subDay()
        ]);

        Content::factory()->count(3)->create([
            'status' => 'draft'
        ]);

        $published = $this->repository->getPublished();

        $this->assertEquals(5, $published->count());
    }

    public function test_can_publish_content()
    {
        $content = Content::factory()->create([
            'status' => 'draft'
        ]);

        $published = $this->repository->publish($content->id);

        $this->assertEquals('published', $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_can_create_and_restore_version()
    {
        $content = Content::factory()->create([
            'title' => 'Original Title'
        ]);

        // Create version
        $version = $this->repository->createVersion($content->id, [
            'type' => 'update',
            'data' => ['title' => 'New Title'] + $content->toArray()
        ]);

        // Update content
        $content->update(['title' => 'New Title']);

        // Restore version
        $restored = $this->repository->restoreVersion($content->id, $version->id);

        $this->assertEquals('Original Title', $restored->title);
    }

    public function test_can_get_related_content()
    {
        $tag = Tag::factory()->create();
        $category = Category::factory()->create();

        $content = Content::factory()->create();
        $content->tags()->attach($tag);
        $content->category()->associate($category);
        $content->save();

        Content::factory()->count(5)->create()->each(function ($c) use ($tag) {
            $c->tags()->attach($tag);
        });

        $related = $this->repository->getRelated($content->id, 3);

        $this->assertEquals(3, $related->count());
    }

    public function test_throws_exception_for_invalid_content()
    {
        $this->expectException(ContentException::class);
        $this->repository->publish(999);
    }

    public function test_can_filter_content_by_type()
    {
        Content::factory()->count(3)->create(['type' => 'article']);
        Content::factory()->count(2)->create(['type' => 'page']);

        $articles = $this->repository->getByType('article');

        $this->assertEquals(3, $articles->count());
    }
}
