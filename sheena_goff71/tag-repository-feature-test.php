<?php

namespace Tests\Feature\Repository;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repository\TagRepositoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Tag\Events\{TagCreated, TagUpdated, TagDeleted};
use Illuminate\Support\Facades\Event;

class TagRepositoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected TagRepositoryFactory $factory;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->factory = app(TagRepositoryFactory::class);
        Event::fake();
    }

    /** @test */
    public function it_can_perform_full_tag_lifecycle()
    {
        // Create tag
        $writeRepo = $this->factory->createWriteRepository();
        $tag = $writeRepo->create([
            'name' => 'Lifecycle Test',
            'description' => 'Testing full lifecycle'
        ]);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Lifecycle Test'
        ]);
        Event::assertDispatched(TagCreated::class);

        // Update tag
        $updatedTag = $writeRepo->update($tag->id, [
            'name' => 'Updated Lifecycle'
        ]);

        $this->assertEquals('Updated Lifecycle', $updatedTag->name);
        Event::assertDispatched(TagUpdated::class);

        // Read tag
        $readRepo = $this->factory->createReadRepository();
        $foundTag = $readRepo->findById($tag->id);
        
        $this->assertEquals($updatedTag->name, $foundTag->name);

        // Add relationships
        $relationshipRepo = $this->factory->createRelationshipRepository();
        $content = Content::factory()->create();
        
        $relationshipRepo->syncRelationships($tag->id, [
            'content_ids' => [$content->id]
        ]);

        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_id' => $content->id
        ]);

        // Delete tag
        $writeRepo->delete($tag->id);
        
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        Event::assertDispatched(TagDeleted::class);
    }

    /** @test */
    public function it_handles_complex_search_operations()
    {
        // Create test data
        Tag::factory()->count(5)->create();
        Tag::factory()->create(['name' => 'Special Tag']);

        // Build complex query
        $queryBuilder = $this->factory->createQueryBuilder();
        $query = $queryBuilder
            ->withSearch('Special')
            ->withUsageStats()
            ->withSorting('created_at', 'desc')
            ->getQuery();

        $results = $query->get();

        $this->assertEquals(1, $results->count());
        $this->assertEquals('Special Tag', $results->first()->name);
    }

    /** @test */
    public function it_maintains_cache_consistency()
    {
        // Create initial tag
        $tag = Tag::factory()->create();

        // Get cache repository
        $cacheRepo = $this->factory->createCacheRepository();

        // Cache the tag
        $cachedTag = $cacheRepo->remember($tag->id);
        $this->assertEquals($tag->name, $cachedTag->name);

        // Update through write repository
        $writeRepo = $this->factory->createWriteRepository();
        $writeRepo->update($tag->id, [
            'name' => 'Updated Through Write Repo'
        ]);

        // Cache should be invalidated
        $freshCachedTag = $cacheRepo->remember($tag->id);
        $this->assertEquals('Updated Through Write Repo', $freshCachedTag->name);
    }

    /** @test */
    public function it_properly_handles_batch_operations()
    {
        // Create test tags
        $tags = Tag::factory()->count(3)->create();

        // Perform batch update
        $writeRepo = $this->factory->createWriteRepository();
        $updates = $tags->mapWithKeys(function ($tag) {
            return [$tag->id => ['name' => "Batch {$tag->id}"]];
        })->toArray();

        $count = $writeRepo->bulkUpdate($updates);

        // Verify results
        $this->assertEquals(3, $count);
        foreach ($tags as $tag) {
            $this->assertDatabaseHas('tags', [
                'id' => $tag->id,
                'name' => "Batch {$tag->id}"
            ]);
        }
    }
}
