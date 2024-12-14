<?php

namespace Tests\Integration\Repository;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Content\Models\Content;
use App\Core\Tag\Repository\TagRepositoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Cache, Queue};
use App\Core\Tag\Jobs\TagCleanupJob;

class TagRepositoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected TagRepositoryFactory $factory;

    public function setUp(): void
    {
        parent::setUp();
        $this->factory = app(TagRepositoryFactory::class);
        Cache::tags(['tags'])->flush();
        Queue::fake();
    }

    /** @test */
    public function it_maintains_data_integrity_during_concurrent_operations()
    {
        $content = Content::factory()->create();
        $tags = Tag::factory()->count(5)->create();
        $writeRepo = $this->factory->createWriteRepository();
        $relationshipRepo = $this->factory->createRelationshipRepository();

        // Simulate concurrent tag attachments
        $promises = [];
        foreach (range(1, 3) as $i) {
            $promises[] = async(function () use ($relationshipRepo, $content, $tags) {
                $relationshipRepo->syncRelationships($content->id, [
                    'tag_ids' => $tags->random(2)->pluck('id')->toArray()
                ]);
            });
        }

        await($promises);

        // Verify data integrity
        $this->assertLessThanOrEqual(5, DB::table('taggables')->count());
        $this->assertGreaterThan(0, DB::table('taggables')->count());
    }

    /** @test */
    public function it_handles_complex_relationships_correctly()
    {
        // Create test data
        $parentTag = Tag::factory()->create(['name' => 'Parent']);
        $childTags = Tag::factory()->count(3)->create();
        $content = Content::factory()->create();

        $relationshipRepo = $this->factory->createRelationshipRepository();

        // Set up relationships
        $relationshipRepo->syncRelationships($parentTag->id, [
            'child_ids' => $childTags->pluck('id')->toArray(),
            'content_ids' => [$content->id]
        ]);

        // Verify relationships
        $hierarchy = $relationshipRepo->getHierarchyRelationships($parentTag->id);
        
        $this->assertCount(3, $hierarchy['children']);
        $this->assertCount(1, $parentTag->contents);
    }

    /** @test */
    public function it_properly_handles_cache_invalidation_chains()
    {
        $tags = Tag::factory()->count(3)->create();
        $content = Content::factory()->create();
        
        $writeRepo = $this->factory->createWriteRepository();
        $cacheRepo = $this->factory->createCacheRepository();
        $relationshipRepo = $this->factory->createRelationshipRepository();

        // Cache initial state
        foreach ($tags as $tag) {
            $cacheRepo->remember($tag->id);
        }

        // Update relationships
        $relationshipRepo->syncRelationships($content->id, [
            'tag_ids' => $tags->pluck('id')->toArray()
        ]);

        // Update one tag
        $writeRepo->update($tags->first()->id, [
            'name' => 'Updated Name'
        ]);

        // Verify cache invalidation
        foreach ($tags as $tag) {
            $this->assertNull(Cache::tags(['tags'])->get("tag:{$tag->id}"));
        }
    }

    /** @test */
    public function it_processes_batch_operations_with_transactions()
    {
        DB::beginTransaction();

        try {
            $tags = Tag::factory()->count(5)->create();
            $writeRepo = $this->factory->createWriteRepository();

            // Perform batch update with deliberate error
            $updates = $tags->mapWithKeys(function ($tag) {
                return [$tag->id => ['name' => str_repeat('a', 300)]]; // Exceeds max length
            })->toArray();

            $writeRepo->bulkUpdate($updates);
            
            DB::commit();
            $this->fail('Should have thrown an exception');
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Verify no changes were made
            foreach ($tags as $tag) {
                $this->assertDatabaseHas('tags', [
                    'id' => $tag->id,
                    'name' => $tag->name
                ]);
            }
        }
    }

    /** @test */
    public function it_correctly_queues_cleanup_jobs()
    {
        $unusedTags = Tag::factory()->count(5)->create();
        $usedTag = Tag::factory()->create();
        $content = Content::factory()->create();

        // Attach one tag to content
        $relationshipRepo = $this->factory->createRelationshipRepository();
        $relationshipRepo->syncRelationships($content->id, [
            'tag_ids' => [$usedTag->id]
        ]);

        // Trigger cleanup
        TagCleanupJob::dispatch();

        Queue::assertPushed(TagCleanupJob::class);
        
        // Process the job
        Queue::assertPushed(function (TagCleanupJob $job) use ($unusedTags) {
            $job->handle();
            
            // Verify cleanup
            foreach ($unusedTags as $tag) {
                $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
            }
            return true;
        });
    }
}
