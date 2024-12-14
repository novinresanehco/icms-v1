<?php

namespace App\Core\Template\Statistics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Template\Exceptions\StatisticsException;

class StatisticsManager
{
    private Collection $trackers;
    private MetricsAggregator $aggregator;
    private PerformanceCollector $performance;
    private StatisticsStorage $storage;
    private array $config;

    public function __construct(
        MetricsAggregator $aggregator,
        PerformanceCollector $performance,
        StatisticsStorage $storage,
        array $config = []
    ) {
        $this->trackers = new Collection();
        $this->aggregator = $aggregator;
        $this->performance = $performance;
        $this->storage = $storage;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Track template usage
     *
     * @param string $template
     * @param array $context
     * @return void
     */
    public function trackUsage(string $template, array $context = []): void
    {
        $data = [
            'template' => $template,
            'timestamp' => now(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'context' => $context,
            'performance' => $this->performance->collect()
        ];

        $this->storage->store('usage', $data);
        $this->notifyTrackers('usage', $data);
    }

    /**
     * Track template render time
     *
     * @param string $template
     * @param float $duration
     * @return void
     */
    public function trackRenderTime(string $template, float $duration): void
    {
        $data = [
            'template' => $template,
            'duration' => $duration,
            'timestamp' => now(),
            'memory' => memory_get_peak_usage(true)
        ];

        $this->storage->store('performance', $data);
        $this->notifyTrackers('performance', $data);
    }

    /**
     * Get template statistics
     *
     * @param string $template
     * @param string $period
     * @return array
     */
    public function getStatistics(string $template, string $period = '24h'): array
    {
        $cacheKey = "template_stats:{$template}:{$period}";

        return Cache::remember($cacheKey, 3600, function () use ($template, $period) {
            $data = $this->storage->retrieve($template, $period);
            return $this->aggregator->aggregate($data);
        });
    }

    /**
     * Register statistics tracker
     *
     * @param StatisticsTracker $tracker
     * @return void
     */
    public function registerTracker(StatisticsTracker $tracker): void
    {
        $this->trackers->push($tracker);
    }

    /**
     * Generate statistics report
     *
     * @param string $period
     * @return array
     */
    public function generateReport(string $period = '24h'): array
    {
        $data = $this->storage->retrieveAll($period);
        
        return [
            'overview' => $this->aggregator->getOverview($data),
            'performance' => $this->aggregator->getPerformanceMetrics($data),
            'usage' => $this->aggregator->getUsageMetrics($data),
            'trends' => $this->aggregator->getTrends($data)
        ];
    }

    /**
     * Notify trackers of event
     *
     * @param string $event
     * @param array $data
     * @return void
     */
    protected function notifyTrackers(string $event, array $data): void
    {
        foreach ($this->trackers as $tracker) {
            try {
                $tracker->track($event, $data);
            } catch (\Exception $e) {
                // Log error but continue processing
                logger()->error("Tracker error: {$e->getMessage()}", [
                    'tracker' => get_class($tracker),
                    'event' => $event
                ]);
            }
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'storage_driver' => 'database',
            'retention_period' => '30 days',
            'track_performance' => true,
            'track_memory' => true,
            'aggregate_interval' => '1h'
        ];
    }
}

class MetricsAggregator
{
    /**
     * Aggregate statistics data
     *
     * @param array $data
     * @return array
     */
    public function aggregate(array $data): array
    {
        return [
            'usage' => $this->getUsageMetrics($data),
            'performance' => $this->getPerformanceMetrics($data),
            'trends' => $this->getTrends($data)
        ];
    }

    /**
     * Get overview metrics
     *
     * @param array $data
     * @return array
     */
    public function getOverview(array $data): array
    {
        return [
            'total_views' => count($data['usage'] ?? []),
            'average_render_time' => $this->calculateAverageRenderTime($data),
            'peak_memory_usage' => $this->calculatePeakMemoryUsage($data),
            'unique_users' => $this->countUniqueUsers($data)
        ];
    }

    /**
     * Get performance metrics
     *
     * @param array $data
     * @return array
     */
    public function getPerformanceMetrics(array $data): array
    {
        $performanceData = $data['performance'] ?? [];

        return [
            'average_duration' => array_sum(array_column($performanceData, 'duration')) / max(1, count($performanceData)),
            'min_duration' => min(array_column($performanceData, 'duration') ?: [0]),
            'max_duration' => max(array_column($performanceData, 'duration') ?: [0]),
            'memory_trend' => $this->calculateMemoryTrend($performanceData)
        ];
    }

    /**
     * Get usage metrics
     *
     * @param array $data
     * @return array
     */
    public function getUsageMetrics(array $data): array
    {
        $usageData = $data['usage'] ?? [];

        return [
            'views_by_hour' => $this->groupByHour($usageData),
            'user_distribution' => $this->getUserDistribution($usageData),
            'context_analysis' => $this->analyzeContext($usageData)
        ];
    }

    /**
     * Get trends data
     *
     * @param array $data
     * @return array
     */
    public function getTrends(array $data): array
    {
        return [
            'usage_trend' => $this->calculateUsageTrend($data),
            'performance_trend' => $this->calculatePerformanceTrend($data)
        ];
    }

    /**
     * Calculate memory trend
     *
     * @param array $data
     * @return array
     */
    protected function calculateMemoryTrend(array $data): array
    {
        $trend = [];
        foreach ($data as $entry) {
            $hour = date('Y-m-d H', strtotime($entry['timestamp']));
            if (!isset($trend[$hour])) {
                $trend[$hour] = [];
            }
            $trend[$hour][] = $entry['memory'];
        }

        return array_map(function ($values) {
            return array_sum($values) / count($values);
        }, $trend);
    }

    /**
     * Group data by hour
     *
     * @param array $data
     * @return array
     */
    protected function groupByHour(array $data): array
    {
        $grouped = [];
        foreach ($data as $entry) {
            $hour = date('Y-m-d H', strtotime($entry['timestamp']));
            $grouped[$hour] = ($grouped[$hour] ?? 0) + 1;
        }
        return $grouped;
    }
}

class PerformanceCollector
{
    private float $startTime;
    private int $startMemory;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * Collect performance metrics
     *
     * @return array
     */
    public function collect(): array
    {
        return [
            'duration' => microtime(true) - $this->startTime,
            'memory' => memory_get_usage(true) - $this->startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'queries' => $this->collectQueryMetrics()
        ];
    }

    /**
     * Collect database query metrics
     *
     * @return array
     */
    protected function collectQueryMetrics(): array
    {
        $queryLog = DB::getQueryLog();
        
        return [
            'count' => count($queryLog),
            'total_time' => array_sum(array_column($queryLog, 'time')),
            'slowest_query' => $this->getSlowestQuery($queryLog)
        ];
    }

    /**
     * Get slowest query
     *
     * @param array $queryLog
     * @return array|null
     */
    protected function getSlowestQuery(array $queryLog): ?array
    {
        if (empty($queryLog)) {
            return null;
        }

        return array_reduce($queryLog, function ($carry, $query) {
            return ($query['time'] > ($carry['time'] ?? 0)) ? $query : $carry;
        });
    }
}

abstract class StatisticsTracker
{
    /**
     * Track statistics event
     *
     * @param string $event
     * @param array $data
     * @return void
     */
    abstract public function track(string $event, array $data): void;
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Statistics\StatisticsManager;
use App\Core\Template\Statistics\MetricsAggregator;
use App\Core\Template\Statistics\PerformanceCollector;
use App\Core\Template\Statistics\StatisticsStorage;

class StatisticsServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(StatisticsManager::class, function ($app) {
            return new StatisticsManager(
                new MetricsAggregator(),
                new PerformanceCollector(),
                new StatisticsStorage(config('template.statistics.storage')),
                config('template.statistics')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Register middleware for statistics collection
        $this->app['router']->pushMiddleware(\App\Http\Middleware\TrackTemplateStatistics::class);
    }
}
