<?php

namespace Tests\Unit\Repositories\Performance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Repositories\ContentRepository;
use App\Core\Database\Performance\DatabasePerformanceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtendedRepositoryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected $performanceManager;
    protected $repository;
    protected $benchmarkThresholds;
    protected $performanceLog = [];

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->performanceManager = app(DatabasePerformanceManager::class);
        $this->repository = new ContentRepository($this->performanceManager);
        
        $this->benchmarkThresholds = [
            'query_time' => 100,
            'memory_usage' => 20 * 1024 * 1024,
            'cache_hit_ratio' => 0.8,
            'index_usage_ratio' => 0.9,
            'query_plan_score' => 0.7
        ];

        $this->seedTestData();
        $this->initializePerformanceLogging();
    }

    protected function initializePerformanceLogging(): void
    {
        DB::listen(function($query) {
            $this->performanceLog[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
                'explain' => DB::select('EXPLAIN ' . $query->sql, $query->bindings)
            ];
        });
    }

    /** @test */
    public function it_optimizes_query_plans()
    {
        // Execute test queries
        $results = $this->repository->with(['category', 'tags'])
            ->whereHas('tags', function($query) {
                $query->where('name', 'like', 'performance%');
            })
            ->paginate(20);

        $suboptimalQueries = array_filter($this->performanceLog, function($log) {
            $explain = $log['explain'][0];
            return $explain->type === 'ALL' || 
                   ($explain->key === null && $explain->rows > 100);
        });

        $queryPlanScore = 1 - (count($suboptimalQueries) / count($this->performanceLog));

        $this->assertGreaterThan(
            $this->benchmarkThresholds['query_plan_score'],
            $queryPlanScore,
            "Query plan optimization score below threshold"
        );
    }

    /** @test */
    public function it_handles_concurrent_access_efficiently()
    {
        $concurrentUsers = 10;
        $startTime = microtime(true);
        
        $promises = [];
        for ($i = 0; $i < $concurrentUsers; $i++) {
            $promises[] = async(function() {
                return $this->repository->paginate(20);
            });
        }
        
        // Wait for all concurrent operations to complete
        await($promises);
        
        $duration = (microtime(true) - $startTime) * 1000;
        $averageTime = $duration / $concurrentUsers;
        
        $this->assertLessThan(
            $this->benchmarkThresholds['query_time'] * 1.5, // Allow 50% overhead for concurrent access
            $averageTime,
            "Concurrent access performance below threshold"
        );
    }

    /** @test */
    public function it_maintains_index_utilization()
    {
        DB::enableQueryLog();
        
        // Perform complex queries that should use indexes
        $this->repository->whereHas('category', function($query) {
            $query->where('status', 'active');
        })->whereBetween('created_at', [now()->subDays(7), now()])
          ->orderBy('updated_at', 'desc')
          ->get();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $indexedQueries = 0;
        foreach ($queries as $query) {
            $explain = DB::select('EXPLAIN ' . $query['query'], $query['bindings'])[0];
            if ($explain->key !== null) {
                $indexedQueries++;
            }
        }

        $indexUsageRatio = $indexedQueries / count($queries);
        
        $this->assertGreaterThan(
            $this->benchmarkThresholds['index_usage_ratio'],
            $indexUsageRatio,
            "Index usage ratio below threshold"
        );
    }

    /** @test */
    public function it_handles_bulk_operations_efficiently()
    {
        $batchSize = 1000;
        $records = $this->generateBulkTestData($batchSize);
        
        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);

        // Test bulk insert
        $chunks = array_chunk($records, 100);
        foreach ($chunks as $chunk) {
            $this->repository->bulkInsert($chunk);
        }

        // Test bulk update
        $this->repository->bulkUpdate(
            ['status' => 'archived'],
            ['created_at' => ['$lt' => now()->subDays(30)]]
        );

        $memoryUsed = memory_get_usage(true) - $startMemory;
        $timeUsed = (microtime(true) - $startTime) * 1000;

        $this->assertLessThan(
            $this->benchmarkThresholds['memory_usage'],
            $memoryUsed,
            "Bulk operation memory usage exceeds threshold"
        );

        $this->assertLessThan(
            $this->benchmarkThresholds['query_time'] * 2,
            $timeUsed,
            "Bulk operation time exceeds threshold"
        );
    }

    protected function generateBulkTestData(int $count): array
    {
        $data = [];
        $statuses = ['draft', 'published', 'archived'];
        
        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'title' => "Bulk Test Content {$i}",
                'content' => "Bulk test content body {$i}",
                'status' => $statuses[array_rand($statuses)],
                'created_at' => now()->subDays(rand(0, 60)),
                'updated_at' => now()
            ];
        }

        return $data;
    }
}
