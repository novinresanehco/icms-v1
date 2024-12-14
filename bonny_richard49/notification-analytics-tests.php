<?php

namespace Tests\Unit\Notification\Analytics;

use Tests\TestCase;
use App\Core\Notification\Analytics\NotificationAnalytics;
use App\Core\Analytics\AnalyticsEngine;
use App\Core\Cache\CacheManager;
use App\Core\Notification\Analytics\Providers\NotificationDataProvider;
use App\Core\Notification\Analytics\Exceptions\AnalyticsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class NotificationAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected NotificationAnalytics $analytics;
    protected AnalyticsEngine $engine;
    protected CacheManager $cache;
    protected NotificationDataProvider $dataProvider;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->engine = $this->mock(AnalyticsEngine::class);
        $this->cache = $this->mock(CacheManager::class);
        $this->dataProvider = $this->mock(NotificationDataProvider::class);
        
        $this->analytics = new NotificationAnalytics(
            $this->engine,
            $this->cache
        );

        Event::fake();
    }

    /** @test */
    public function it_analyzes_performance_data_correctly()
    {
        // Arrange
        $filters = ['start_date' => now()->subDays(7)];
        $mockData = $this->getMockPerformanceData();
        
        $this->dataProvider->expects('gatherPerformanceData')
            ->with($filters)
            ->andReturn($mockData);

        // Act
        $result = $this->analytics->analyzePerformance($filters);

        // Assert
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('delivery_stats', $result);
        $this->assertArrayHasKey('engagement_stats', $result);
        
        Event::assertDispatched(AnalyticsProcessed::class);
    }

    /** @test */
    public function it_analyzes_user_segments_with_caching()
    {
        // Arrange
        $filters = ['segment' => 'premium'];
        $mockData = $this->getMockSegmentData();
        
        $this->cache->expects('remember')
            ->with(
                $this->stringContains('notification_analytics:segments'),
                3600,
                $this->isType('callable')
            )
            ->andReturn($mockData);

        // Act
        $result = $this->analytics->analyzeUserSegments($filters);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('premium', $result);
        $this->assertArrayHasKey('engagement_score', $result['premium']);
    }

    /** @test */
    public function it_handles_data_provider_exceptions_gracefully()
    {
        // Arrange
        $this->dataProvider->expects('gatherChannelData')
            ->andThrow(new DataProviderException('Database error'));

        // Assert
        $this->expectException(AnalyticsException::class);
        
        // Act
        $this->analytics->analyzeChannelEffectiveness();
    }

    /** @test */
    public function it_validates_ab_test_data_before_analysis()
    {
        // Arrange
        $testId = 'test-123';
        $invalidData = collect([
            ['variant' => 'A', 'total_sent' => 5], // Too small sample
        ]);

        $this->dataProvider->expects('gatherTestData')
            ->with($testId)
            ->andReturn($invalidData);

        // Assert
        $this->expectException(ValidationException::class);
        
        // Act
        $this->analytics->analyzeABTests($testId);
    }

    /** @test */
    public function it_generates_optimization_suggestions_correctly()
    {
        // Arrange
        $metrics = [
            'delivery_rate' => 0.85,
            'error_rate' => 0.15,
            'cost_per_delivery' => 0.05
        ];

        // Act
        $suggestions = $this->analytics->generateOptimizationSuggestions($metrics);

        // Assert
        $this->assertIsArray($suggestions);
        $this->assertNotEmpty($suggestions);
        $this->assertArrayHasKey('priority', $suggestions[0]);
    }

    protected function getMockPerformanceData(): Collection
    {
        return collect([
            [
                'type' => 'email',
                'status' => 'delivered',
                'total' => 1000,
                'avg_delivery_time' => 2.5,
                'avg_read_time' => 30
            ]
        ]);
    }

    protected function getMockSegmentData(): array
    {
        return [
            'premium' => [
                'total_notifications' => 1000,
                'read_rate' => 85.5,
                'avg_time_to_read' => 25.5,
                'engagement_score' => 0.75
            ]
        ];
    }
}
