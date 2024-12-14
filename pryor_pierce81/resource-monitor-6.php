<?php

namespace App\Core\Monitoring\Resource;

class ResourceMonitor {
    private SystemMetricsCollector $systemMetrics;
    private DatabaseMetricsCollector $dbMetrics;
    private CacheMetricsCollector $cacheMetrics;
    private ResourceAnalyzer $analyzer;
    private AlertManager $alertManager;

    public function __construct(
        SystemMetricsCollector $systemMetrics,
        DatabaseMetricsCollector $dbMetrics,
        CacheMetricsCollector $cacheMetrics,
        ResourceAnalyzer $analyzer,
        AlertManager $alertManager
    ) {
        $this->systemMetrics = $systemMetrics;
        $this->dbMetrics = $dbMetrics;
        $this->cacheMetrics = $cacheMetrics;
        $this->analyzer = $analyzer;
        $this->alertManager = $alertManager;
    }

    public function collectMetrics(): ResourceMetrics 
    {
        $metrics = new ResourceMetrics([
            'system' => $this->systemMetrics->collect(),
            'database' => $this->dbMetrics->collect(),
            'cache' => $this->cacheMetrics->collect()
        ]);

        $analysis = $this->analyzer->analyze($metrics);
        
        if ($analysis->hasIssues()) {
            $this->alertManager->notify($analysis->getIssues());
        }

        return $metrics;
    }
}

class SystemMetricsCollector {
    public function collect(): array 
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'network' => $this->getNetworkUsage()
        ];
    }

    private function getCpuUsage(): array 
    {
        $load = sys_getloadavg();
        return [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }

    private function getMemoryUsage(): array 
    {
        $memory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        return [
            'current' => $memory,
            'peak' => $peakMemory,
            'limit' => ini_get('memory_limit')
        ];
    }

    private function getDiskUsage(): array 
    {
        $path = storage_path();
        return [
            'free' => disk_free_space($path),
            'total' => disk_total_space($path)
        ];
    }

    private function getNetworkUsage(): array 
    {
        // Implementation depends on system capabilities
        return [
            'connections' => $this->getActiveConnections(),
            'bandwidth' => $this->getCurrentBandwidth()
        ];
    }

    private function getActiveConnections(): int 
    {
        return (int)shell_exec('netstat -an | grep ESTABLISHED | wc -l');
    }

    private function getCurrentBandwidth(): array 
    {
        // Simplified implementation
        return [
            'in' => 0,
            'out' => 0
        ];
    }
}

class DatabaseMetricsCollector {
    private \PDO $connection;

    public function __construct(\PDO $connection) 
    {
        $this->connection = $connection;
    }

    public function collect(): array 
    {
        return [
            'connections' => $this->getActiveConnections(),
            'queries' => $this->getQueryMetrics(),
            'storage' => $this->getStorageMetrics()
        ];
    }

    private function getActiveConnections(): array 
    {
        $stmt = $this->connection->query('SHOW STATUS WHERE Variable_name = "Threads_connected"');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return [
            'current' => (int)$result['Value'],
            'max' => (int)ini_get('mysql.max_connections')
        ];
    }

    private function getQueryMetrics(): array 
    {
        $metrics = [];
        $vars = [
            'Queries',
            'Slow_queries',
            'Questions',
            'Com_select',
            'Com_insert',
            'Com_update',
            'Com_delete'
        ];

        $stmt = $this->connection->query('SHOW GLOBAL STATUS WHERE Variable_name IN ("' . implode('","', $vars) . '")');
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $metrics[$row['Variable_name']] = (int)$row['Value'];
        }

        return $metrics;
    }

    private function getStorageMetrics(): array 
    {
        $stmt = $this->connection->query('SELECT table_schema, SUM(data_length + index_length) as size FROM information_schema.tables GROUP BY table_schema');
        $schemas = [];
        
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $schemas[$row['table_schema']] = (int)$row['size'];
        }

        return $schemas;
    }
}

class CacheMetricsCollector {
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache) 
    {
        $this->cache = $cache;
    }

    public function collect(): array 
    {
        return [
            'hits' => $this->cache->getHits(),
            'misses' => $this->cache->getMisses(),
            'memory' => $this->getMemoryUsage(),
            'keys' => $this->getKeyMetrics()
        ];
    }

    private function getMemoryUsage(): array 
    {
        $info = $this->cache->getInfo();
        return [
            'used' => $info['used_memory'],
            'peak' => $info['used_memory_peak'],
            'fragmentation' => $info['mem_fragmentation_ratio']
        ];
    }

    private function getKeyMetrics(): array 
    {
        $info = $this->cache->getInfo();
        return [
            'total' => $info['keys'],
            'expires' => $info['expires'],
            'evicted' => $info['evicted_keys']
        ];
    }
}

class ResourceMetrics {
    private array $metrics;
    private float $timestamp;

    public function __construct(array $metrics) 
    {
        $this->metrics = $metrics;
        $this->timestamp = microtime(true);
    }

    public function getMetrics(): array 
    {
        return $this->metrics;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }

    public function toArray(): array 
    {
        return [
            'metrics' => $this->metrics,
            'timestamp' => $this->timestamp,
            'collected_at' => date('Y-m-d H:i:s', (int)$this->timestamp)
        ];
    }
}

class ResourceAnalyzer {
    private array $thresholds;

    public function __construct(array $thresholds) 
    {
        $this->thresholds = $thresholds;
    }

    public function analyze(ResourceMetrics $metrics): AnalysisResult 
    {
        $issues = [];
        
        foreach ($metrics->getMetrics() as $type => $data) {
            $typeIssues = $this->analyzeMetricType($type, $data);
            if (!empty($typeIssues)) {
                $issues[$type] = $typeIssues;
            }
        }

        return new AnalysisResult($issues, $metrics->getTimestamp());
    }

    private function analyzeMetricType(string $type, array $data): array 
    {
        $issues = [];
        $thresholds = $this->thresholds[$type] ?? [];

        foreach ($data as $metric => $value) {
            if (isset($thresholds[$metric])) {
                $threshold = $thresholds[$metric];
                if ($this->isThresholdExceeded($value, $threshold)) {
                    $issues[] = [
                        'metric' => $metric,
                        'value' => $value,
                        'threshold' => $threshold,
                        'severity' => $this->calculateSeverity($value, $threshold)
                    ];
                }
            }
        }

        return $issues;
    }

    private function isThresholdExceeded($value, $threshold): bool 
    {
        if (is_array($value)) {
            return $this->isComplexThresholdExceeded($value, $threshold);
        }

        return $value > $threshold;
    }

    private function isComplexThresholdExceeded(array $value, array $threshold): bool 
    {
        foreach ($threshold as $key => $limit) {
            if (isset($value[$key]) && $value[$key] > $limit) {
                return true;
            }
        }
        
        return false;
    }

    private function calculateSeverity($value, $threshold): string 
    {
        $ratio = is_array($value) 
            ? $this->calculateComplexRatio($value, $threshold)
            : $value / $threshold;

        if ($ratio >= 1.5) return 'critical';
        if ($ratio >= 1.2) return 'warning';
        return 'info';
    }

    private function calculateComplexRatio(array $value, array $threshold): float 
    {
        $ratios = [];
        foreach ($threshold as $key => $limit) {
            if (isset($value[$key]) && $limit > 0) {
                $ratios[] = $value[$key] / $limit;
            }
        }

        return empty($ratios) ? 0 : max($ratios);
    }
}

class AnalysisResult {
    private array $issues;
    private float $timestamp;

    public function __construct(array $issues, float $timestamp) 
    {
        $this->issues = $issues;
        $this->timestamp = $timestamp;
    }

    public function hasIssues(): bool 
    {
        return !empty($this->issues);
    }

    public function getIssues(): array 
    {
        return $this->issues;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }
}
