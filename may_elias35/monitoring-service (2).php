<?php

namespace App\Core\Monitoring;

use App\Core\Interfaces\MonitoringInterface;
use App\Core\Exceptions\{
    MonitoringException,
    ValidationException,
    SecurityException
};
use Illuminate\Support\Facades\{Cache, Log, DB};

class MonitoringService implements MonitoringInterface 
{
    private ValidationService $validator;
    private SecurityService $security;
    private MetricsCollector $metrics;
    private array $config;

    private const MONITOR_PREFIX = 'monitor:';
    private const PATTERN_PREFIX = 'pattern:';
    private const MAX_PATTERNS = 1000;
    private const ALERT_THRESHOLD = 0.95;

    public function __construct(
        ValidationService $validator,
        SecurityService $security,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function startOperation(string $operationId): void
    {
        $this->validateOperationId($operationId);
        
        $context = [
            'operation_id' => $operationId,
            'start_time' => microtime(true),
            'node_id' => gethostname(),
            'system_state' => $this->captureSystemState()
        ];

        Cache::put(
            $this->getMonitorKey($operationId),
            $context,
            $this->config['operation_ttl']
        );

        $this->metrics->incrementCounter('operations_started');
    }

    public function track(array $context, callable $operation): mixed
    {
        $operationId = $context['operation_id'];
        $startTime = microtime(true);

        try {
            $this->validateContext($context);
            $this->checkOperationLimits($context);
            
            $result = $operation();
            
            $this->validateResult($result);
            $this->recordSuccess($operationId, $startTime);
            
            return $result;

        } catch (\Exception $e) {
            $this->recordFailure($operationId, $e);
            throw $e;
        } finally {
            $this->updateMetrics($operationId, $startTime);
        }
    }

    public function detectAnomaly(string $operationType, array $patterns): bool
    {
        $historicalPatterns = $this->loadPatternHistory($operationType);
        $anomalyScore = $this->calculateAnomalyScore($patterns, $historicalPatterns);
        
        if ($anomalyScore > self::ALERT_THRESHOLD) {
            $this->handleAnomalyDetection($operationType, $anomalyScore);
            return true;
        }

        $this->updatePatternHistory($operationType, $patterns);
        return false;
    }

    public function checkResourceAvailability(string $type, int $quantity): bool
    {
        $available = $this->metrics->getResourceMetric($type);
        $threshold = $this->config['resource_thresholds'][$type];
        
        return ($available - $quantity) >= $threshold;
    }

    public function isQuotaExceeded(array $context): bool
    {
        $quota = $this->config['quotas'][$context['type']] ?? PHP_INT_MAX;
        $used = $this->metrics->getQuotaUsage($context['type']);
        
        return $used >= $quota;
    }

    public function isThresholdExceeded(string $metric): bool
    {
        $current = $this->metrics->getCurrentValue($metric);
        $threshold = $this->config['thresholds'][$metric] ?? PHP_INT_MAX;
        
        return $current >= $threshold;
    }

    public function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'disk_usage' => disk_free_space('/'),
            'active_connections' => $this->getActiveConnections(),
            'queue_size' => $this->getQueueSize(),
            'cache_stats' => $this->getCacheStats()
        ];
    }

    protected function validateOperationId(string $operationId): void
    {
        if (!$this->validator->validateIdentifier($operationId)) {
            throw new ValidationException('Invalid operation ID format');
        }
    }

    protected function validateContext(array $context): void
    {
        if (!$this->validator->validateMonitoringContext($context)) {
            throw new ValidationException('Invalid monitoring context');
        }

        if (!$this->security->validateContext($context)) {
            throw new SecurityException('Security context validation failed');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateOperationResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function checkOperationLimits(array $context): void
    {
        if ($this->isQuotaExceeded($context)) {
            throw new MonitoringException('Operation quota exceeded');
        }

        if ($this->isThresholdExceeded($context['metric'])) {
            throw new MonitoringException('Operation threshold exceeded');
        }
    }

    protected function recordSuccess(string $operationId, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->recordMetric('operation_duration', $duration);
        $this->metrics->incrementCounter('operations_succeeded');
        
        Log::info('Operation completed successfully', [
            'operation_id' => $operationId,
            'duration' => $duration
        ]);
    }

    protected function recordFailure(string $operationId, \Exception $e): void
    {
        $this->metrics->incrementCounter('operations_failed');
        
        Log::error('Operation failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function updateMetrics(string $operationId, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $operation = Cache::get($this->getMonitorKey($operationId));
        
        if ($operation) {
            $this->metrics->recordMetrics([
                'operation_duration' => $duration,
                'memory_peak' => memory_get_peak_usage(true),
                'cpu_usage' => sys_getloadavg()[0]
            ]);
        }
    }

    protected function loadPatternHistory(string $operationType): array
    {
        $key = $this->getPatternKey($operationType);
        return Cache::get($key, []);
    }

    protected function calculateAnomalyScore(array $patterns, array $history): float
    {
        if (empty($history)) {
            return 0.0;
        }

        $differences = array_map(
            fn($pattern) => $this->calculatePatternDifference($pattern, $history),
            $patterns
        );

        return array_sum($differences) / count($differences);
    }

    protected function calculatePatternDifference(array $pattern, array $history): float
    {
        $matches = array_filter(
            $history,
            fn($historical) => $this->patternsMatch($pattern, $historical)
        );

        return empty($matches) ? 1.0 : 0.0;
    }

    protected function updatePatternHistory(string $operationType, array $patterns): void
    {
        $key = $this->getPatternKey($operationType);
        $history = $this->loadPatternHistory($operationType);
        
        $history = array_merge($patterns, $history);
        $history = array_slice($history, 0, self::MAX_PATTERNS);
        
        Cache::put($key, $history, $this->config['pattern_ttl']);
    }

    protected function handleAnomalyDetection(string $operationType, float $score): void
    {
        Log::warning('Anomaly detected', [
            'operation_type' => $operationType,
            'anomaly_score' => $score,
            'system_state' => $this->captureSystemState()
        ]);

        $this->metrics->incrementCounter('anomalies_detected');
        $this->security->handleAnomaly($operationType, $score);
    }

    protected function getMonitorKey(string $operationId): string
    {
        return self::MONITOR_PREFIX . $operationId;
    }

    protected function getPatternKey(string $operationType): string
    {
        return self::PATTERN_PREFIX . $operationType;
    }

    protected function getActiveConnections(): int
    {
        return DB::select('SHOW STATUS LIKE "Threads_connected"')[0]->Value;
    }

    protected function getQueueSize(): int
    {
        return Cache::get('queue:size', 0);
    }

    protected function getCacheStats(): array
    {
        return [
            'hits' => Cache::get('stats:hits', 0),
            'misses' => Cache::get('stats:misses', 0),
            'memory' => Cache::get('stats:memory', 0)
        ];
    }
}
