<?php

namespace Tests\Unit\Repository;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repository\{
    TagReadRepository,
    TagWriteRepository,
    TagCacheRepository,
    TagRelationshipRepository
};
use App\Core\Tag\Exceptions\TagWriteException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class TagRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected TagReadRepository $readRepository;
    protected TagWriteRepository $writeRepository;
    protected TagCacheRepository $cacheRepository;
    protected TagRelationshipRepository $relationshipRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->readRepository = app(TagReadRepository::class);
        $this->writeRepository = app(TagWriteRepository::class);
        $this->cacheRepository = app(TagCacheRepository::class);
        $this->relationshipRepository = app(TagRelationshipRepository::class);

        // Clear cache before each test
        Cache::tags(['tags'])->flush();
    }

    /** @test */
    public function it_can_create_a_tag()
    {
        Event::fake();

        $data = [
            'name' => 'Test Tag',
            'description' => 'Test Description'
        ];

        $tag = $this->writeRepository->create($data);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Test Tag'
        ]);

        Event::assertDispatched('tag.created');
    }

    /** @test */
    public function it_can_update_a_tag()
    {
        $tag = Tag::factory()->create();

        $updateData = [
            'name' => 'Updated Tag',
            'description' => 'Updated Description'
        ];

        $updatedTag = $this->writeRepository->update($tag->id, $updateData);

        $this->assertEquals('Updated Tag', $updatedTag->name);
        $this->assertEquals('Updated Description', $updatedTag->description);
    }

    /** @test */
    public function it_can_delete_a_tag()
    {
        $tag = Tag::factory()->create();

        $result = $this->writeRepository->delete($tag->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    /** @test */
    public function it_can_find_tag_by_id()
    {
        $tag = Tag::factory()->create();

        $foundTag = $this->readRepository->findById($tag->id);

        $this->assertNotNull($foundTag);
        $this->assertEquals($tag->id, $foundTag->id);
    }

    /** @test */
    public function it_can_search_tags()
    {
        Tag::factory()->create(['name' => 'First Tag']);
        Tag::factory()->create(['name' => 'Second Tag']);

        $criteria = [
            'search' => 'First',
            'filters' => [],
            'sort' => 'name',
            'direction' => 'asc'
        ];

        $results = $this->readRepository->search($criteria);

        $this->assertEquals(1, $results->count());
        $this->assertEquals('First Tag', $results->first()->name);
    }

    /** @test */
    public function it_caches_tags_correctly()
    {
        $tag = Tag::factory()->create();

        // First call should hit database
        $cachedTag = $this->cacheRepository->remember($tag->id);
        
        // Second call should hit cache
        $cachedTag2 = $this->cacheRepository->remember($tag->id);

        $this->assertEquals($tag->id, $cachedTag->id);
        $this->assertEquals($cachedTag->id, $cachedTag2->id);
    }

    /** @test */
    public function it_clears_cache_on_update()
    {
        $tag = Tag::factory()->create();

        // Cache the tag
        $this->cacheRepository->remember($tag->id);

        // Update the tag
        $this->writeRepository->update($tag->id, ['name' => 'Updated Name']);

        // Cache should be cleared
        $this->assertNull(Cache::tags(['tags'])->get("tag:{$tag->id}"));
    }

    /** @test */
    public function it_handles_relationship_sync()
    {
        $tag = Tag::factory()->create();
        $content = Content::factory()->create();

        $relationships = [
            'content_ids' => [$content->id]
        ];

        $this->relationshipRepository->syncRelationships($tag->id, $relationships);

        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_id' => $content->id,
            'taggable_type' => get_class($content)
        ]);
    }

    /** @test */
    public function it_throws_exception_for_invalid_tag_update()
    {
        $this->expectException(TagWriteException::class);

        $this->writeRepository->update(999, [
            'name' => 'Invalid Tag'
        ]);
    }

    /** @test */
    public function it_can_bulk_update_tags()
    {
        $tags = Tag::factory()->count(3)->create();

        $updates = $tags->mapWithKeys(function ($tag) {
            return [$tag->id => ['name' => "Updated {$tag->id}"]];
        })->toArray();

        $updatedCount = $this->writeRepository->bulkUpdate($updates);

        $this->assertEquals(3, $updatedCount);
        
        foreach ($tags as $tag) {
            $this->assertDatabaseHas('tags', [
                'id' => $tag->id,
                'name' => "Updated {$tag->id}"
            ]);
        }
    }

    /** @test */
    public function it_handles_cache_warmup()
    {
        $tags = Tag::factory()->count(5)->create();

        $this->cacheRepository->warmUp($tags->pluck('id')->toArray());

        foreach ($tags as $tag) {
            $this->assertTrue(
                Cache::tags(['tags'])->has("tag:{$tag->id}")
            );
        }
    }
}
