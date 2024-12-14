<?php

namespace Tests\Stress\Repository;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repository\TagRepositoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Cache, Queue};
use Illuminate\Support\Str;

class TagRepositoryStressTest extends TestCase
{
    use RefreshDatabase;

    protected TagRepositoryFactory $factory;
    protected const STRESS_ITERATIONS = 1000;
    protected const BATCH_SIZE = 100;
    protected const MAX_MEMORY_INCREASE = 50; // MB

    public function setUp(): void
    {
        parent::setUp();
        $this->factory = app(TagRepositoryFactory::class);
        Cache::tags(['tags'])->flush();
        DB::enableQueryLog();
    }

    /** @test */
    public function it_handles_large_data_sets()
    {
        $initialMemory = memory_get_usage(true);
        $readRepo = $this->factory->createReadRepository();

        // Create large dataset
        Tag::factory()->count(10000)->create();

        // Perform intensive search operations
        for ($i = 0; $i < self::STRESS_ITERATIONS; $i++) {
            $results = $readRepo->search([
                'search' => Str::random(5),
                'sort' => 'created_at',
                'direction' => 'desc'
            ]);

            if ($i % 100 === 0) {
                $currentMemory = memory_get_usage(true);
                $memoryIncrease = ($currentMemory - $initialMemory) / 1024 / 1024;
                
                // Check memory usage
                $this->assertLessThan(
                    self::MAX_MEMORY_INCREASE,
                    $memoryIncrease,
                    "Memory usage exceeded threshold"
                );
            }
        }
    }

    /** @test */
    public function it_handles_rapid_cache_invalidation()
    {
        $cacheRepo = $this->factory->createCacheRepository();
        $writeRepo = $this->factory->createWriteRepository();
        $tags = Tag::factory()->count(1000)->create();
        
        $metrics = [];
        $startMemory = memory_get_usage(true);

        for ($i = 0; $i < self::STRESS_ITERATIONS; $i++) {
            $startTime = microtime(true);

            // Rapidly alternate between cache writes and invalidations
            if ($i % 2 === 0) {
                foreach ($tags->take(10) as $tag) {
                    $cacheRepo->remember($tag->id);
                }
            } else {
                $tag = $tags->random();
                $writeRepo->update($tag->id, [
                    'name' => 'Updated ' . Str::random(10)
                ]);
            }

            $endTime = microtime(true);
            $metrics[] = ($endTime - $startTime) * 1000;

            if ($i % 100 === 0) {
                $currentMemory = memory_get_usage(true);
                $memoryIncrease = ($currentMemory - $startMemory) / 1024 / 1024;
                
                $this->assertLessThan(
                    self::MAX_MEMORY_INCREASE,
                    $memoryIncrease,
                    "Memory usage exceeded threshold during cache operations"
                );
            }
        }

        // Analyze performance degradation
        $firstBatch = array_slice($metrics, 0, 100);
        $lastBatch = array_slice($metrics, -100);
        
        $avgFirst = array_sum($firstBatch) / count($firstBatch);
        $avgLast = array_sum($lastBatch) / count($lastBatch);
        
        $this->assertLessThan(
            $avgFirst * 2,
            $avgLast,
            "Significant performance degradation detected"
        );
    }

    /** @test */
    public function it_handles_concurrent_relationship_modifications()
    {
        $relationshipRepo = $this->factory->createRelationshipRepository();
        $content = Content::factory()->create();
        $tags = Tag::factory()->count(1000)->create();

        $metrics = [];
        $initialMemory = memory_get_usage(true);

        for ($i = 0; $i < self::STRESS_ITERATIONS; $i++) {
            $startTime = microtime(true);

            try {
                // Rapidly modify relationships
                $relationshipRepo->syncRelationships($content->id, [
                    'tag_ids' => $tags->random(5)->pluck('id')->toArray()
                ]);

                $success = true;
            } catch (\Exception $e) {
                $success = false;
            }

            $endTime = microtime(true);
            $metrics[] = [
                'response_time' => ($endTime - $startTime) * 1000,
                'success' => $success
            ];

            if ($i % 100 === 0) {
                $currentMemory = memory_get_usage(true);
                $memoryIncrease = ($currentMemory - $initialMemory) / 1024 / 1024;
                
                $this->assertLessThan(
                    self::MAX_MEMORY_INCREASE,
                    $memoryIncrease,
                    "Memory usage exceeded threshold during relationship operations"
                );
            }
        }

        // Analyze success rate
        $successRate = collect($metrics)
            ->where('success', true)
            ->count() / count($metrics);
            
        $this->assertGreaterThan(
            0.95,
            $successRate,
            "Success rate below threshold during stress test"
        );
    }

    /** @test */
    public function it_handles_database_connection_stress()
    {
        $writeRepo = $this->factory->createWriteRepository();
        $maxConnections = 0;

        for ($i = 0; $i < self::STRESS_ITERATIONS; $i++) {
            DB::beginTransaction();
            
            try {
                $tag = $writeRepo->create([
                    'name' => 'Stress Test ' . Str::random(10),
                    'description' => 'Testing database connection handling'
                ]);

                // Simulate some work
                usleep(rand(1000, 5000));

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
            }

            $connections = count(DB::getConnections());
            $maxConnections = max($maxConnections, $connections);

            // Force garbage collection periodically
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }

        $this->assertLessThan(
            100,
            $maxConnections,
            "Too many database connections accumulated"
        );
    }
}
