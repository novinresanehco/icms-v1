<?php

namespace App\Core\Monitoring\Performance;

class PerformanceMonitor
{
    private MetricsCollector $metricsCollector;
    private ThresholdManager $thresholdManager;
    private AlertDispatcher $alertDispatcher;
    private PerformanceAnalyzer $analyzer;
    private MetricsStorage $storage;

    public function monitor(string $key, callable $operation, array $tags = []): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $operation();
            $this->recordSuccess($key, $startTime, $startMemory, $tags);
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($key, $startTime, $startMemory, $e, $tags);
            throw $e;
        }
    }

    private function recordSuccess(string $key, float $startTime, int $startMemory, array $tags): void
    {
        $metrics = $this->calculateMetrics($startTime, $startMemory);
        $this->metricsCollector->record($key, $metrics, $tags);
        
        if ($this->thresholdManager->isThresholdExceeded($key, $metrics)) {
            $this->alertDispatcher->dispatchThresholdAlert($key, $metrics);
        }

        $this->analyzer->analyzeMetrics($key, $metrics);
        $this->storage->store($key, $metrics, $tags);
    }

    private function recordFailure(string $key, float $startTime, int $startMemory, \Throwable $error, array $tags): void
    {
        $metrics = $this->calculateMetrics($startTime, $startMemory);
        $metrics['error'] = [
            'type' => get_class($error),
            'message' => $error->getMessage()
        ];

        $this->metricsCollector->record($key, $metrics, $tags);
        $this->alertDispatcher->dispatchErrorAlert($key, $error, $metrics);
        $this->storage->store($key, $metrics, $tags);
    }

    private function calculateMetrics(float $startTime, int $startMemory): array
    {
        return [
            'duration' => microtime(true) - $startTime,
            'memory' => memory_get_usage() - $startMemory,
            'peak_memory' => memory_get_peak_usage(),
            'timestamp' => time()
        ];
    }
}

class PerformanceAnalyzer
{
    private AnomalyDetector $anomalyDetector;
    private TrendAnalyzer $trendAnalyzer;
    private ResourceAnalyzer $resourceAnalyzer;
    private array $analyzers;

    public function analyzeMetrics(string $key, array $metrics): AnalysisResult
    {
        $results = [];
        
        foreach ($this->analyzers as $analyzer) {
            $results[] = $analyzer->analyze($key, $metrics);
        }

        $anomalies = $this->anomalyDetector->detect($key, $metrics);
        $trends = $this->trendAnalyzer->analyze($key, $metrics);
        $resources = $this->resourceAnalyzer->analyze($metrics);

        return new AnalysisResult(
            $results,
            $anomalies,
            $trends,
            $resources
        );
    }
}

class ThresholdManager
{
    private array $thresholds;
    private ThresholdLoader $loader;
    private array $cache = [];

    public function isThresholdExceeded(string $key, array $metrics): bool
    {
        $threshold = $this->getThreshold($key);
        if (!$threshold) {
            return false;
        }

        return $this->evaluateThreshold($threshold, $metrics);
    }

    private function getThreshold(string $key): ?array
    {
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->loader->load($key);
        }

        return $this->cache[$key];
    }

    private function evaluateThreshold(array $threshold, array $metrics): bool
    {
        foreach ($threshold as $metric => $limit) {
            if (!isset($metrics[$metric])) {
                continue;
            }

            if ($metrics[$metric] > $limit) {
                return true;
            }
        }

        return false;
    }
}

class AlertDispatcher
{
    private AlertChannelManager $channelManager;
    private AlertFormatter $formatter;
    private AlertPolicy $policy;

    public function dispatchThresholdAlert(string $key, array $metrics): void
    {
        if (!$this->policy->shouldAlert($key, $metrics)) {
            return;
        }

        $alert = $this->formatter->formatThresholdAlert($key, $metrics);
        $this->dispatch($alert);
    }

    public function dispatchErrorAlert(string $key, \Throwable $error, array $metrics): void
    {
        $alert = $this->formatter->formatErrorAlert($key, $error, $metrics);
        $this->dispatch($alert);
    }

    private function dispatch(Alert $alert): void
    {
        $channels = $this->channelManager->getChannelsForAlert($alert);
        
        foreach ($channels as $channel) {
            try {
                $channel->send($alert);
            } catch (\Exception $e) {
                // Log failed alert dispatch
            }
        }
    }
}

class MetricsStorage
{
    private \PDO $db;
    private string $table;
    private MetricsSerializer $serializer;

    public function store(string $key, array $metrics, array $tags): void
    {
        $serializedMetrics = $this->serializer->serialize($metrics);
        $serializedTags = $this->serializer->serialize($tags);

        $sql = "INSERT INTO {$this->table} (key, metrics, tags, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$key, $serializedMetrics, $serializedTags]);
    }

    public function query(array $criteria): array
    {
        $conditions = [];
        $params = [];

        foreach ($criteria as $field => $value) {
            $conditions[] = "$field = ?";
            $params[] = $value;
        }

        $whereClause = empty($conditions) ? "" : "WHERE " . implode(" AND ", $conditions);
        
        $sql = "SELECT * FROM {$this->table} {$whereClause} ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(function ($row) {
            $row['metrics'] = $this->serializer->unserialize($row['metrics']);
            $row['tags'] = $this->serializer->unserialize($row['tags']);
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}

class AnalysisResult
{
    private array $results;
    private array $anomalies;
    private array $trends;
    private array $resources;
    private float $timestamp;

    public function __construct(array $results, array $anomalies, array $trends, array $resources)
    {
        $this->results = $results;
        $this->anomalies = $anomalies;
        $this->trends = $trends;
        $this->resources = $resources;
        $this->timestamp = microtime(true);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getAnomalies(): array
    {
        return $this->anomalies;
    }

    public function getTrends(): array
    {
        return $this->trends;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}