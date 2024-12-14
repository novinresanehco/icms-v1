<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Repositories\BaseRepository;
use App\Core\Services\Cache\CacheService;
use App\Core\Services\Performance\PerformanceMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;

class RepositoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected $repository;
    protected $performanceMonitor;
    protected array $queryLog = [];
    protected float $startMemory;
    protected float $startTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startMemory = memory_get_usage(true);
        $this->startTime = microtime(true);
        
        // Enable query logging
        DB::enableQueryLog();
        
        // Setup query listener
        DB::listen(function (QueryExecuted $query) {
            $this->queryLog[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
                'connection' => $query->connectionName,
                'timestamp' => microtime(true)
            ];
        });
    }

    protected function tearDown(): void
    {
        $this->analyzeTestPerformance();
        DB::disableQueryLog();
        parent::tearDown();
    }

    protected function analyzeTestPerformance(): void
    {
        $endMemory = memory_get_usage(true);
        $endTime = microtime(true);
        
        $metrics = [
            'memory_usage' => $endMemory - $this->startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => $endTime - $this->startTime,
            'query_count' => count($this->queryLog),
            'total_query_time' => array_sum(array_column($this->queryLog, 'time'))
        ];

        $this->addToAssertionCount(1);
        
        // Assert performance constraints
        $this->assertLessThan(
            50 * 1024 * 1024, // 50MB
            $metrics['memory_usage'],
            'Memory usage exceeded limit'
        );
        
        $this->assertLessThan(
            1000, // 1 second
            $metrics['execution_time'],
            'Execution time exceeded limit'
        );
    }
}

class ContentRepositoryTest extends RepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->app->make(ContentRepository::class);
    }

    /** @test */
    public function it_efficiently_retrieves_paginated_content()
    {
        // Arrange
        $this->createManyContent(100);
        
        // Act
        $result = $this->repository->paginate(10);
        
        // Assert
        $this->assertCount(10, $result->items());
        $this->assertLessThan(5, count($this->queryLog), 'Too many queries executed');
        $this->assertQueryTimeBelow(100); // 100ms
    }

    /** @test */
    public function it_properly_uses_cache()
    {
        // Arrange
        $content = $this->createContent();
        $cacheKey = "content:{$content->id}";
        
        // Act - First call (cache miss)
        $result1 = $this->repository->find($content->id);
        $queryCount1 = count($this->queryLog);
        
        // Clear query log
        $this->queryLog = [];
        
        // Act - Second call (cache hit)
        $result2 = $this->repository->find($content->id);
        $queryCount2 = count($this->queryLog);
        
        // Assert
        $this->assertEquals($result1->id, $result2->id);
        $this->assertGreaterThan(0, $queryCount1, 'First call should hit database');
        $this->assertEquals(0, $queryCount2, 'Second call should use cache');
    }

    /** @test */
    public function it_handles_concurrent_updates_correctly()
    {
        // Arrange
        $content = $this->createContent();
        
        // Act
        $updates = collect(range(1, 5))->map(function ($i) use ($content) {
            return function () use ($content, $i) {
                return $this->repository->update($content->id, [
                    'title' => "Update {$i}"
                ]);
            };
        });
        
        // Run updates concurrently
        $results = $this->runConcurrently($updates);
        
        // Assert
        $this->assertCount(5, $results);
        $this->assertNoDuplicateVersions($content);
    }

    protected function assertQueryTimeBelow(int $maxTime): void
    {
        $maxQueryTime = max(array_column($this->queryLog, 'time'));
        $this->assertLessThan(
            $maxTime,
            $maxQueryTime,
            "Query time of {$maxQueryTime}ms exceeds maximum of {$maxTime}ms"
        );
    }

    protected function runConcurrently(Collection $callbacks)
    {
        return $callbacks->map(function ($callback) {
            return DB::transaction(function () use ($callback) {
                return $callback();
            });
        });
    }
}
