<?php

namespace App\Core\Monitoring;

use App\Core\Metrics\MetricsCollectorInterface;
use App\Core\Infrastructure\ResourceManager;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditLogger;

class PerformanceMonitoringService implements MonitoringInterface
{
    private MetricsCollectorInterface $metrics;
    private ResourceManager $resources;
    private CacheManager $cache;
    private AuditLogger $audit;

    private const ALERT_THRESHOLD = 80; // %
    private const CHECK_INTERVAL = 5; // seconds
    private const METRICS_TTL = 3600; // 1 hour

    public function __construct(
        MetricsCollectorInterface $metrics,
        ResourceManager $resources,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->metrics = $metrics;
        $this->resources = $resources;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function monitor(): MonitoringResult
    {
        $monitoringId = uniqid('monitor_', true);
        
        try {
            // Collect current metrics
            $performance = $this->collectPerformanceMetrics();
            
            // Check system resources
            $resources = $this->checkResourceUsage();
            
            // Analyze metrics
            $analysis = $this->analyzeMetrics($performance, $resources);
            
            // Handle alerts if needed
            $this->handleAlerts($analysis);
            
            // Store metrics
            $this->storeMetrics($performance, $resources);
            
            return new MonitoringResult($performance, $resources, $analysis);
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e, $monitoringId);
            throw $e;
        }
    }

    private function collectPerformanceMetrics(): array
    {
        return [
            'response_time' => $this->metrics->getAverageResponseTime(),
            'throughput' => $this->metrics->getCurrentThroughput(),
            'error_rate' => $this->metrics->getErrorRate(),
            'queue_size' => $this->metrics->getQueueSize(),
            'cache_hit_ratio' => $this->cache->getHitRatio()
        ];
    }

    private function checkResourceUsage(): array
    {
        return [
            'cpu_usage' => $this->resources->getCpuUsage(),
            'memory_usage' => $this->resources->getMemoryUsage(),
            'disk_usage' => $this->resources->getDiskUsage(),
            'network_usage' => $this->resources->getNetworkUsage(),
            'connection_count' => $this->resources->getConnectionCount()
        ];
    }

    private function analyzeMetrics(array $performance, array $resources): array
    {
        $analysis = [];
        
        // Performance analysis
        $analysis['performance'] = $this->analyzePerformanceMetrics($performance);
        
        // Resource analysis
        $analysis['resources'] = $this->analyzeResourceMetrics($resources);
        
        // Trend analysis
        $analysis['trends'] = $this->analyzeTrends($performance, $resources);
        
        return $analysis;
    }

    private function analyzePerformanceMetrics(array $metrics): array
    {
        $issues = [];
        
        if ($metrics['response_time'] > 200) {
            $issues[] = 'high_response_time';
        }
        
        if ($metrics['error_rate'] > 1) {
            $issues[] = 'high_error_rate';
        }
        
        if ($metrics['queue_size'] > 1000) {
            $issues[] = 'large_queue_size';
        }
        
        if ($metrics['cache_hit_ratio'] < 0.7) {
            $issues[] = 'low_cache_hits';
        }
        
        return [
            'status' => empty($issues) ? 'healthy' : 'degraded',
            'issues' => $issues
        ];
    }

    private function analyzeResourceMetrics(array $metrics): array
    {
        $issues = [];
        
        if ($metrics['cpu_usage'] > self::ALERT_THRESHOLD) {
            $issues[] = 'high_cpu_usage';
        }
        
        if ($metrics['memory_usage'] > self::ALERT_THRESHOLD) {
            $issues[] = 'high_memory_usage';
        }
        
        if ($metrics['disk_usage'] > self::ALERT_THRESHOLD) {
            $issues[] = 'high_disk_usage';
        }
        
        if ($metrics['network_usage'] > self::ALERT_THRESHOLD) {
            $issues[] = 'high_network_usage';
        }
        
        return [
            'status' => empty($issues) ? 'healthy' : 'stressed',
            'issues' => $issues
        ];
    }

    private function analyzeTrends(array $current, array $resources): array
    {
        $historicalData = $this->metrics->getHistoricalData(self::CHECK_INTERVAL);
        
        return [
            'performance_trend' => $this->calculateTrend($historicalData['performance']),
            'resource_trend' => $this->calculateTrend($historicalData['resources']),
            'prediction' => $this->predictTrend($historicalData)
        ];
    }

    private function handleAlerts(array $analysis): void
    {
        foreach ($analysis as $category => $data) {
            if (!empty($data['issues'])) {
                foreach ($data['issues'] as $issue) {
                    $this->raiseAlert($category, $issue);
                }
            }
        }
    }

    private function raiseAlert(string $category, string $issue): void
    {
        $this->audit->logAlert("performance_alert", [
            'category' => $category,
            'issue' => $issue,
            'timestamp' => now()
        ]);
        
        if ($this->isHighPriorityIssue($issue)) {
            $this->triggerHighPriorityAlert($category, $issue);
        }
    }

    private function storeMetrics(array $performance, array $resources): void
    {
        $this->metrics->store('performance', $performance);
        $this->metrics->store('resources', $resources);
        
        $this->cache->set(
            'latest_monitoring_metrics',
            [
                'performance' => $performance,
                'resources' => $resources,
                'timestamp' => now()
            ],
            self::METRICS_TTL
        );
    }

    private function calculateTrend(array $data): string
    {
        // Implementation of trend calculation
        $trend = 0;
        
        foreach ($data as $point) {
            $trend += $point['value'] - $point['previous'];
        }
        
        if ($trend > 0) return 'increasing';
        if ($trend < 0) return 'decreasing';
        return 'stable';
    }

    private function predictTrend(array $data): array
    {
        // Implementation of trend prediction
        return [
            'direction' => $this->calculateTrendDirection($data),
            'confidence' => $this->calculatePredictionConfidence($data)
        ];
    }

    private function handleMonitoringFailure(\Exception $e, string $monitoringId): void
    {
        $this->audit->logCritical('monitoring_failure', [
            'monitoring_id' => $monitoringId,
            'error' => $e->getMessage(),
            '