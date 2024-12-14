namespace App\Core\Infrastructure;

class InfrastructureManager implements InfrastructureManagerInterface
{
    private CacheManager $cache;
    private MonitoringService $monitor;
    private ErrorHandler $errorHandler;
    private PerformanceTracker $performanceTracker;
    private SystemHealthCheck $healthCheck;
    private ConfigManager $config;

    public function __construct(
        CacheManager $cache,
        MonitoringService $monitor,
        ErrorHandler $errorHandler,
        PerformanceTracker $performanceTracker,
        SystemHealthCheck $healthCheck,
        ConfigManager $config
    ) {
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->errorHandler = $errorHandler;
        $this->performanceTracker = $performanceTracker;
        $this->healthCheck = $healthCheck;
        $this->config = $config;
    }

    public function executeWithInfrastructureProtection(callable $operation, array $context): mixed
    {
        // Start monitoring
        $trackingId = $this->monitor->startOperation($context);
        
        try {
            // Track performance
            $startTime = microtime(true);
            
            // Execute with cache check
            $result = $this->executeCached($operation, $context);
            
            // Record metrics
            $this->recordOperationMetrics($trackingId, microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Handle infrastructure errors
            $this->handleInfrastructureError($e, $trackingId, $context);
            throw $e;
        } finally {
            // Always stop monitoring
            $this->monitor->stopOperation($trackingId);
        }
    }

    private function executeCached(callable $operation, array $context): mixed
    {
        $cacheKey = $this->generateCacheKey($context);
        
        return $this->cache->remember($cacheKey, function() use ($operation, $context) {
            return $operation();
        }, $this->getCacheDuration($context));
    }

    private function handleInfrastructureError(\Throwable $e, string $trackingId, array $context): void
    {
        // Log error with full context
        $this->errorHandler->logError($e, [
            'tracking_id' => $trackingId,
            'context' => $context,
            'system_state' => $this->healthCheck->getCurrentState()
        ]);

        // Record failure metrics
        $this->monitor->recordFailure($trackingId, $e);

        // Execute recovery if possible
        if ($this->canRecover($e)) {
            $this->executeRecoveryProcedure($e, $context);
        }

        // Notify system administrators if critical
        if ($this->isCriticalError($e)) {
            $this->notifyAdministrators($e, $trackingId);
        }
    }

    private function recordOperationMetrics(string $trackingId, float $duration): void
    {
        $this->performanceTracker->recordMetrics([
            'tracking_id' => $trackingId,
            'duration' => $duration,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'cache_stats' => $this->cache->getStats(),
            'system_load' => $this->healthCheck->getSystemLoad()
        ]);
    }

    private function generateCacheKey(array $context): string
    {
        return hash('xxh3', serialize($context));
    }

    private function getCacheDuration(array $context): int
    {
        return $this->config->get('cache.durations.' . ($context['cache_group'] ?? 'default'), 3600);
    }

    private function canRecover(\Throwable $e): bool
    {
        return $e instanceof RecoverableException && 
               $this->healthCheck->hasAvailableResources();
    }

    private function isCriticalError(\Throwable $e): bool
    {
        return $e instanceof CriticalException || 
               $this->healthCheck->isSystemCompromised();
    }

    private function executeRecoveryProcedure(\Throwable $e, array $context): void
    {
        try {
            $this->healthCheck->executeRecovery($context);
        } catch (\Exception $recoveryError) {
            $this->errorHandler->logError($recoveryError, [
                'original_error' => $e,
                'context' => $context,
                'recovery_attempted' => true
            ]);
        }
    }

    private function notifyAdministrators(\Throwable $e, string $trackingId): void
    {
        $systemState = $this->healthCheck->getCurrentState();
        $metrics = $this->performanceTracker->getCurrentMetrics();
        
        event(new CriticalSystemError($e, $trackingId, $systemState, $metrics));
    }
}

class CacheManager implements CacheManagerInterface
{
    private array $stores = [];
    private MetricsCollector $metrics;
    private ErrorHandler $errorHandler;

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        try {
            if ($this->has($key)) {
                $this->metrics->incrementCacheHit($key);
                return $this->get($key);
            }

            $value = $callback();
            $this->put($key, $value, $ttl);
            $this->metrics->incrementCacheMiss($key);
            
            return $value;
            
        } catch (\Exception $e) {
            $this->errorHandler->logError($e, [
                'cache_key' => $key,
                'ttl' => $ttl
            ]);
            
            // Return fresh value on cache error
            return $callback();
        }
    }

    public function tags(array $tags): self
    {
        return new static($this->store->tags($tags));
    }

    public function getStats(): array
    {
        return [
            'hits' => $this->metrics->getCacheHits(),
            'misses' => $this->metrics->getCacheMisses(),
            'size' => $this->store->size(),
            'uptime' => $this->store->uptime()
        ];
    }
}

class MonitoringService implements MonitoringServiceInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private ThresholdValidator $validator;
    private ConfigManager $config;

    public function startOperation(array $context): string
    {
        $trackingId = $this->generateTrackingId();
        
        $this->metrics->initializeOperation($trackingId, [
            'start_time' => microtime(true),
            'context' => $context
        ]);
        
        return $trackingId;
    }

    public function recordMetric(string $trackingId, string $metric, $value): void
    {
        $this->metrics->record($trackingId, $metric, $value);
        
        // Check thresholds
        if ($this->validator->isThresholdExceeded($metric, $value)) {
            $this->alerts->trigger(
                new ThresholdAlert($trackingId, $metric, $value)
            );
        }
    }

    private function generateTrackingId(): string
    {
        return uniqid('op_', true);
    }
}
