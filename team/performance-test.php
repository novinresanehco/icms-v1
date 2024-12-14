<?php

namespace App\Core\Tests\Performance;

use PHPUnit\Framework\TestCase;
use App\Core\Services\ContentService;
use App\Core\Cache\SmartCache;
use App\Core\Monitoring\PerformanceMonitor;

class ContentPerformanceTest extends TestCase
{
    protected ContentService $contentService;
    protected SmartCache $cache;
    protected PerformanceMonitor $monitor;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->contentService = app(ContentService::class);
        $this->cache = app(SmartCache::class);
        $this->monitor = app(PerformanceMonitor::class);
    }

    /**
     * Tests content creation performance under load
     *
     * @test
     * @group performance
     * @group critical
     */
    public function testBulkContentCreationPerformance(): void
    {
        $sampleSize = 100;
        $maxAllowedTime = 100; // milliseconds per operation
        $totalTime = 0;
        $successCount = 0;

        // Prepare test data
        $contents = $this->generateTestContents($sampleSize);

        foreach ($contents as $content) {
            $startTime = microtime(true);
            
            try {
                $result = $this->contentService->create($content);
                $endTime = microtime(true);
                
                $operationTime = ($endTime - $startTime) * 1000;
                $totalTime += $operationTime;
                
                // Track in performance monitor
                $this->monitor->recordMetric('content_creation', $operationTime);
                
                // Validate cache
                $this->assertTrue($this->cache->has("content:{$result->id}"), 
                    "Content {$result->id} must be cached immediately");
                
                // Validate cache retrieval time
                $cacheStart = microtime(true);
                $cachedContent = $this->cache->get("content:{$result->id}");
                $cacheTime = (microtime(true) - $cacheStart) * 1000;
                
                $this->assertLessThan(50, $cacheTime, 
                    "Cache retrieval must be under 50ms, got {$cacheTime}ms");
                
                $successCount++;
            } catch (\Exception $e) {
                $this->monitor->recordError('content_creation_failed', [
                    'error' => $e->getMessage(),
                    'content_id' => $content['title']
                ]);
            }
        }

        // Calculate and validate metrics
        $averageTime = $totalTime / $sampleSize;
        $successRate = ($successCount / $sampleSize) * 100;

        // Assert performance requirements
        $this->assertLessThan($maxAllowedTime, $averageTime, 
            "Average creation time {$averageTime}ms exceeds limit of {$maxAllowedTime}ms");
        
        $this->assertGreaterThan(95, $successRate, 
            "Success rate {$successRate}% is below required 95%");

        // Log performance metrics
        $this->monitor->recordMetric('bulk_operation_completed', [
            'average_time' => $averageTime,
            'success_rate' => $successRate,
            'sample_size' => $sampleSize
        ]);
    }

    /**
     * Generates test content data
     */
    private function generateTestContents(int $count): array
    {
        $contents = [];
        for ($i = 0; $i < $count; $i++) {
            $contents[] = [
                'title' => "Performance Test Content {$i}",
                'content' => "Test content body {$i} with rich text formatting",
                'status' => 'draft',
                'categories' => [1, 2], // Assuming categories exist
                'meta' => [
                    'seo_title' => "SEO Title {$i}",
                    'description' => "Meta description {$i}"
                ],
                'author_id' => 1
            ];
        }
        return $contents;
    }
}
