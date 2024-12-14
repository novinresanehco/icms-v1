<?php

namespace Tests\Unit\Repositories\Performance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Repositories\ContentRepository;
use App\Core\Database\Performance\DatabasePerformanceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RepositoryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected $performanceManager;
    protected $repository;
    protected $benchmarkThresholds;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->performanceManager = app(DatabasePerformanceManager::class);
        $this->repository = new ContentRepository($this->performanceManager);
        
        $this->benchmarkThresholds = [
            'query_time' => 100, // milliseconds
            'memory_usage' => 20 * 1024 * 1024, // 20MB
            'cache_hit_ratio' => 0.8 // 80%
        ];

        // Prepare test data
        $this->seedTestData();
    }

    protected function seedTestData(): void
    {
        // Create test content entries
        for ($i = 0; $i < 100; $i++) {
            $this->repository->create([
                'title' => "Performance Test Content {$i}",
                'content' => "Performance test content body {$i}",
                'status' => $i % 2 === 0 ? 'published' : 'draft'
            ]);
        }
    }

    /** @test */
    public function it_meets_query_performance_thresholds()
    {
        DB::enableQueryLog();
        $startTime = microtime(true);

        // Perform multiple operations
        $this->repository->all();
        $this->repository->paginate(20);
        $this->repository->findWhere(['status' => 'published']);

        $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        // Assert performance metrics
        $this->assertLessThan(
            $this->benchmarkThresholds['query_time'],
            $duration,
            "Query execution time exceeds threshold"
        );

        foreach ($queryLog as $query) {
            $this->assertLessThan(
                $this->benchmarkThresholds['query_time'] / count($queryLog),
                $query['time'],
                "Individual query time exceeds threshold"
            );
        }
    }

    /** @test */
    public function it_maintains_efficient_cache_hit_ratio()
    {
        $totalRequests = 100;
        $cacheHits = 0;

        // Perform multiple cached operations
        for ($i = 0; $i < $totalRequests; $i++) {
            Cache::tags(['content'])->flush();
            
            // First access - cache miss
            $this->repository->find(($i % 50) + 1);
            
            // Second access - should be cache hit
            if (Cache::tags(['content'])->has($this->repository->getCacheKey('find', ['id' => ($i % 50) + 1]))) {
                $cacheHits++;
            }
        }

        $hitRatio = $cacheHits / $totalRequests;
        
        $this->assertGreaterThan(
            $this->benchmarkThresholds['cache_hit_ratio'],
            $hitRatio,
            "Cache hit ratio below threshold"
        );
    }

    /** @test */
    public function it_handles_memory_efficiently()
    {
        $initialMemory = memory_get_usage(true);

        // Perform memory-intensive operations
        $results = $this->repository->with(['category', 'tags', 'author'])->all();
        
        foreach ($results as $result) {
            // Access relationships to trigger lazy loading
            $result->category;
            $result->tags;
            $result->author;
        }

        $peakMemory = memory_get_peak_usage(true) - $initialMemory;
        
        $this->assertLessThan(
            $this->benchmarkThresholds['memory_usage'],
            $peakMemory,
            "Memory usage exceeds threshold"
        );
    }

    /** @test */
    public function it_scales_efficiently_with_data_size()
    {
        $dataSizes = [100, 500, 1000];
        $timings = [];

        foreach ($dataSizes as $size) {
            // Add more test data
            $this->seedBatchTestData($size);

            $startTime = microtime(true);
            
            // Perform operations
            $this->repository->paginate(50);
            $this->repository->findWhere(['status' => 'published']);
            
            $timings[$size] = microtime(true) - $startTime;
        }

        // Assert linear or sub-linear scaling
        $this->assertScalingEfficiency($timings);
    }

    protected function seedBatchTestData(int $count): void
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'title' => "Batch Test Content {$i}",
                'content' => "Batch test content body {$i}",
                'status' => $i % 2 === 0 ? 'published' : 'draft',
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        DB::table('contents')->insert($data);
    }

    protected function assertScalingEfficiency(array $timings): void
    {
        $previousTime = array_shift($timings);
        $previousSize = 100;

        foreach ($timings as $size => $time) {
            $scaleFactor = $time / $previousTime;
            $sizeFactor = $size / $previousSize;
            
            // Assert sub-linear scaling (time should increase slower than data size)
            $this->assertLessThan(
                $sizeFactor,
                $scaleFactor,
                "Performance does not scale efficiently between {$previousSize} and {$size} items"
            );

            $previousTime = $time;
            $previousSize = $size;
        }
    }
}
