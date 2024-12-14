<?php

namespace Tests\Load\Repository;

use Tests\TestCase;
use App\Core\Tag\Models\Tag;
use App\Core\Tag\Repository\TagRepositoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Cache, Queue};
use ParallelUnit\ParallelUnit;

class TagRepositoryLoadTest extends TestCase
{
    use RefreshDatabase;

    protected TagRepositoryFactory $factory;
    protected const CONCURRENT_USERS = 50;
    protected const TEST_DURATION = 60; // seconds
    protected const MAX_RESPONSE_TIME = 200; // milliseconds

    public function setUp(): void
    {
        parent::setUp();
        $this->factory = app(TagRepositoryFactory::class);
        Cache::tags(['tags'])->flush();
        DB::enableQueryLog();
    }

    /** @test */
    public function it_handles_concurrent_read_operations()
    {
        // Prepare test data
        Tag::factory()->count(1000)->create();
        $readRepo = $this->factory->createReadRepository();
        $metrics = [];

        ParallelUnit::create(self::CONCURRENT_USERS)
            ->duration(self::TEST_DURATION)
            ->perform(function () use ($readRepo, &$metrics) {
                $startTime = microtime(true);
                
                $result = $readRepo->search([
                    'search' => 'test',
                    'sort' => 'created_at',
                    'direction' => 'desc'
                ]);

                $endTime = microtime(true);
                $responseTime = ($endTime - $startTime) * 1000;

                $metrics[] = [
                    'response_time' => $responseTime,
                    'success' => !empty($result),
                    'timestamp' => now()
                ];
            });

        // Analyze results
        $averageResponseTime = collect($metrics)->average('response_time');
        $successRate = collect($metrics)
            ->where('success', true)
            ->count() / count($metrics);
        
        $this->assertLessThan(self::MAX_RESPONSE_TIME, $averageResponseTime);
        $this->assertGreaterThan(0.95, $successRate); // 95% success rate
    }

    /** @test */
    public function it_handles_concurrent_write_operations()
    {
        $writeRepo = $this->factory->createWriteRepository();
        $metrics = [];

        ParallelUnit::create(self::CONCURRENT_USERS)
            ->duration(self::TEST_DURATION)
            ->perform(function () use ($writeRepo, &$metrics) {
                $startTime = microtime(true);

                try {
                    $tag = $writeRepo->create([
                        'name' => 'Test Tag ' . uniqid(),
                        'description' => 'Load test tag'
                    ]);

                    $success = !empty($tag);
                } catch (\Exception $e) {
                    $success = false;
                }

                $endTime = microtime(true);
                $responseTime = ($endTime - $startTime) * 1000;

                $metrics[] = [
                    'response_time' => $responseTime,
                    'success' => $success,
                    'timestamp' => now()
                ];
            });

        // Analyze results
        $averageResponseTime = collect($metrics)->average('response_time');
        $successRate = collect($metrics)
            ->where('success', true)
            ->count() / count($metrics);
        
        $this->assertLessThan(self::MAX_RESPONSE_TIME * 2, $averageResponseTime);
        $this->assertGreaterThan(0.90, $successRate); // 90% success rate
    }

    /** @test */
    public function it_handles_mixed_operations_under_load()
    {
        $readRepo = $this->factory->createReadRepository();
        $writeRepo = $this->factory->createWriteRepository();
        $metrics = [];

        ParallelUnit::create(self::CONCURRENT_USERS)
            ->duration(self::TEST_DURATION)
            ->perform(function () use ($readRepo, $writeRepo, &$metrics) {
                $operation = rand(0, 1) ? 'read' : 'write';
                $startTime = microtime(true);

                try {
                    if ($operation === 'read') {
                        $result = $readRepo->search(['search' => 'test']);
                        $success = !empty($result);
                    } else {
                        $tag = $writeRepo->create([
                            'name' => 'Test Tag ' . uniqid(),
                            'description' => 'Mixed load test tag'
                        ]);
                        $success = !empty($tag);
                    }
                } catch (\Exception $e) {
                    $success = false;
                }

                $endTime = microtime(true);
                $responseTime = ($endTime - $startTime) * 1000;

                $metrics[] = [
                    'operation' => $operation,
                    'response_time' => $responseTime,
                    'success' => $success,
                    'timestamp' => now()
                ];
            });

        // Analyze results by operation type
        $readMetrics = collect($metrics)->where('operation', 'read');
        $writeMetrics = collect($metrics)->where('operation', 'write');

        $this->assertMetrics($readMetrics, self::MAX_RESPONSE_TIME, 0.95);
        $this->assertMetrics($writeMetrics, self::MAX_RESPONSE_TIME * 2, 0.90);
    }

    /** @test */
    public function it_maintains_data_integrity_under_load()
    {
        $initialCount = Tag::count();
        $writeRepo = $this->factory->createWriteRepository();
        $created = [];

        ParallelUnit::create(self::CONCURRENT_USERS)
            ->duration(self::TEST_DURATION)
            ->perform(function () use ($writeRepo, &$created) {
                try {
                    $tag = $writeRepo->create([
                        'name' => 'Integrity Test ' . uniqid(),
                        'description' => 'Integrity test under load'
                    ]);
                    if ($tag) {
                        $created[] = $tag->id;
                    }
                } catch (\Exception $e) {
                    // Log error
                }
            });

        $finalCount = Tag::count();
        $actualCreated = count($created);
        $expectedDifference = $finalCount - $initialCount;

        $this->assertEquals(
            $expectedDifference,
            $actualCreated,
            'Data integrity mismatch under load'
        );

        // Verify all created tags exist
        foreach ($created as $tagId) {
            $this->assertDatabaseHas('tags', ['id' => $tagId]);
        }
    }

    protected function assertMetrics($metrics, $maxResponseTime, $minSuccessRate)
    {
        $averageResponseTime = $metrics->average('response_time');
        $successRate = $metrics->where('success', true)->count() / $metrics->count();

        $this->assertLessThan($maxResponseTime, $averageResponseTime);
        $this->assertGreaterThan($minSuccessRate, $successRate);
    }
}
