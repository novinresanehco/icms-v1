<?php

namespace App\Core\Audit;

class AuditAnalyticsEngine
{
    private DataAggregator $aggregator;
    private PatternAnalyzer $patternAnalyzer;
    private TrendAnalyzer $trendAnalyzer;
    private AnomalyDetector $anomalyDetector;
    private MetricsCalculator $metricsCalculator;
    private CacheManager $cache;

    public function __construct(
        DataAggregator $aggregator,
        PatternAnalyzer $patternAnalyzer,
        TrendAnalyzer $trendAnalyzer,
        AnomalyDetector $anomalyDetector,
        MetricsCalculator $metricsCalculator,
        CacheManager $cache
    ) {
        $this->aggregator = $aggregator;
        $this->patternAnalyzer = $patternAnalyzer;
        $this->trendAnalyzer = $trendAnalyzer;
        $this->anomalyDetector = $anomalyDetector;
        $this->metricsCalculator = $metricsCalculator;
        $this->cache = $cache;
    }

    public function analyzeEvents(array $events, AnalyticsConfig $config): AnalyticsResult
    {
        try {
            // Check cache first
            $cacheKey = $this->generateCacheKey($events, $config);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Prepare data
            $preparedData = $this->prepareData($events);

            // Perform analytics
            $result = new AnalyticsResult([
                'aggregations' => $this->performAggregations($preparedData, $config),
                'patterns' => $this->analyzePatterns($preparedData, $config),
                'trends' => $this->analyzeTrends($preparedData, $config),
                'anomalies' => $this->detectAnomalies($preparedData, $config),
                'metrics' => $this->calculateMetrics($preparedData, $config),
                'insights' => $this->generateInsights($preparedData, $config)
            ]);

            // Cache results
            $this->cache->put($cacheKey, $result, $config->getCacheDuration());

            return $result;

        } catch (\Exception $e) {
            throw new AnalyticsException(
                'Failed to analyze audit events: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function prepareData(array $events): PreparedData
    {
        return (new DataPreparer())
            ->normalize($events)
            ->validate()
            ->transform()
            ->filter()
            ->prepare();
    }

    protected function performAggregations(PreparedData $data, AnalyticsConfig $config): array
    {
        $aggregations = [];

        // Time-based aggregations
        $aggregations['by_time'] = $this->aggregator->aggregateByTime($data, [
            'intervals' => $config->getTimeIntervals(),
            'metrics' => $config->getTimeMetrics()
        ]);

        // User-based aggregations
        $aggregations['by_user'] = $this->aggregator->aggregateByUser($data, [
            'metrics' => $config->getUserMetrics(),
            'limit' => $config->getUserLimit()
        ]);

        // Type-based aggregations
        $aggregations['by_type'] = $this->aggregator->aggregateByType($data, [
            'metrics' => $config->getTypeMetrics(),
            'categories' => $config->getTypeCategories()
        ]);

        // Severity-based aggregations
        $aggregations['by_severity'] = $this->aggregator->aggregateBySeverity($data, [
            'metrics' => $config->getSeverityMetrics(),
            'thresholds' => $config->getSeverityThresholds()
        ]);

        return $aggregations;
    }

    protected function analyzePatterns(PreparedData $data, AnalyticsConfig $config): array
    {
        return $this->patternAnalyzer
            ->setConfig($config->getPatternConfig())
            ->analyze($data)
            ->getResults();
    }

    protected function analyzeTrends(PreparedData $data, AnalyticsConfig $config): array
    {
        return $this->trendAnalyzer
            ->setConfig($config->getTrendConfig())
            ->analyze($data)
            ->withSeasonalAdjustment()
            ->withOutlierRemoval()
            ->getResults();
    }

    protected function detectAnomalies(PreparedData $data, AnalyticsConfig $config): array
    {
        return $this->anomalyDetector
            ->setConfig($config->getAnomalyConfig())
            ->analyze($data)
            ->withStatisticalAnalysis()
            ->withMachineLearning()
            ->getResults();
    }

    protected function calculateMetrics(PreparedData $data, AnalyticsConfig $config): array
    {
        return $this->metricsCalculator
            ->setConfig($config->getMetricsConfig())
            ->calculate($data)
            ->withHistoricalComparison()
            ->withBenchmarking()
            ->getResults();
    }

    protected function generateInsights(PreparedData $data, AnalyticsConfig $config): array
    {
        $insights = [];

        // Security insights
        $insights['security'] = $this->generateSecurityInsights($data);

        // Performance insights
        $insights['performance'] = $this->generatePerformanceInsights($data);

        // Usage insights
        $insights['usage'] = $this->generateUsageInsights($data);

        // Risk insights
        $insights['risk'] = $this->generateRiskInsights($data);

        return $insights;
    }

    protected function generateSecurityInsights(PreparedData $data): array
    {
        return [
            'potential_threats' => $this->identifyPotentialThreats($data),
            'security_patterns' => $this->analyzeSecurityPatterns($data),
            'risk_indicators' => $this->calculateRiskIndicators($data),
            'recommendations' => $this->generateSecurityRecommendations($data)
        ];
    }

    protected function generatePerformanceInsights(PreparedData $data): array
    {
        return [
            'bottlenecks' => $this->identifyBottlenecks($data),
            'resource_usage' => $this->analyzeResourceUsage($data),
            'optimization_opportunities' => $this->findOptimizationOpportunities($data),
            'recommendations' => $this->generatePerformanceRecommendations($data)
        ];
    }

    protected function generateUsageInsights(PreparedData $data): array
    {
        return [
            'usage_patterns' => $this->analyzeUsagePatterns($data),
            'user_behavior' => $this->analyzeUserBehavior($data),
            'feature_adoption' => $this->analyzeFeatureAdoption($data),
            'recommendations' => $this->generateUsageRecommendations($data)
        ];
    }

    protected function generateCacheKey(array $events, AnalyticsConfig $config): string
    {
        return 'audit_analytics:' . md5(serialize([
            'event_ids' => array_column($events, 'id'),
            'config' => $config->toArray(),
            'timestamp' => floor(time() / $config->getCacheInterval())
        ]));
    }
}
