<?php

namespace Tests\Performance\Repository;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repository\TagRepositoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Cache};

class TagRepositoryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected TagRepositoryFactory $factory;
    protected const PERFORMANCE_THRESHOLD = 100; // milliseconds
    protected const CACHE_HIT_RATIO_THRESHOLD = 0.8; // 80%

    public function setUp(): void
    {
        parent::setUp();
        $this->factory = app(TagRepositoryFactory::class);
        Cache::tags(['tags'])->flush();
        DB::enableQueryLog();
    }

    /** @test */
    public function search_operations_meet_performance_requirements()
    {
        // Prepare test data
        Tag::factory()->count(1000)->create();
        
        $queryBuilder = $this->factory->createQueryBuilder();
        $startTime = microtime(true);

        // Perform complex search
        $results = $queryBuilder
            ->withSearch('Test')
            ->withRelations(['contents'])
            ->withUsageStats()
            ->withSorting('created_at', 'desc')
            ->getQuery()
            ->paginate(20);

        $executionTime = (microtime(true) - $startTime) * 1000;

        $this->assertLessThan(
            self::PERFORMANCE_THRESHOLD,
            $executionTime,
            "Search operation exceeded performance threshold"
        );

        // Verify query count
        $this->assertLessThan(
            5,
            count(DB::getQueryLog()),
            "Search generated too many queries"
        );
    }

    /** @test */
    public function cache_operations_maintain_efficiency()
    {
        $tags = Tag::factory()->count(100)->create();
        $cacheRepo = $this->factory->createCacheRepository();
        $hits = 0;
        $total = 0;

        // Warm up cache
        foreach ($tags->take(50) as $tag) {
            $cacheRepo->remember($tag->id);
        }

        // Perform cache operations
        foreach ($tags as $tag) {
            $startTime = microtime(true);
            
            $cachedTag = $cacheRepo->remember($tag->id);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $total++;
            if ($executionTime < 1) { // Assuming cache hits are faster than 1ms
                $hits++;
            }
        }

        $hitRatio = $hits / $total;
        
        $this->assertGreaterThan(
            self::CACHE_HIT_RATIO_THRESHOLD,
            $hitRatio,
            "Cache hit ratio below threshold"
        );
    }

    /** @test */
    public function bulk_operations_scale_linearly()
    {
        $sampleSizes = [10, 100, 1000];
        $timings = [];

        foreach ($sampleSizes as $size) {
            $tags = Tag::factory()->count($size)->create();
            $writeRepo = $this->factory->createWriteRepository();

            $updates = $tags->mapWithKeys(function ($tag) {
                return [$tag->id => ['name' => "Updated {$tag->id}"]];
            })->toArray();

            $startTime = microtime(true);
            
            $writeRepo->bulkUpdate($updates);
            
            $timings[$size] = (microtime(true) - $startTime) * 1000;
        }

        // Verify linear scaling (roughly)
        $baselineTime = $timings[10];
        foreach ([100, 1000] as $size) {
            $expectedMax = $baselineTime * ($size / 10) * 1.5; // Allow 50% overhead
            $this->assertLessThan(
                $expectedMax,
                $timings[$size],
                "Bulk operation scaling exceeds linear threshold"
            );
        }
    }

    /** @test */
    public function relationship_operations_maintain_performance()
    {
        // Create test data
        $tag = Tag::factory()->create();
        $contents = Content::factory()->count(100)->create();
        
        $relationshipRepo = $this->factory->createRelationshipRepository();
        
        $startTime = microtime(true);

        // Perform relationship sync
        $relationshipRepo->syncRelationships($tag->id, [
            'content_ids' => $contents->pluck('id')->toArray()
        ]);

        $executionTime = (microtime(true) - $startTime) * 1000;

        $this->assertLessThan(
            self::PERFORMANCE_THRESHOLD,
            $executionTime,
            "Relationship sync exceeded performance threshold"
        );

        // Verify query count
        $this->assertLessThan(
            3,
            count(DB::getQueryLog()),
            "Relationship sync generated too many queries"
        );
    }
}
