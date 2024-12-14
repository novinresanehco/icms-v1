<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditLogger
};
use App\Core\Exceptions\{
    CacheException,
    PerformanceException,
    SecurityException
};

class PerformanceManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function cacheOperation(string $key, callable $operation, array $context = []): mixed
    {
        return $this->security->executeCriticalOperation(function() use ($key, $operation, $context) {
            $this->validateCacheOperation($key, $context);
            
            $cacheKey = $this->generateCacheKey($key, $context);
            
            if ($this->hasValidCache($cacheKey)) {
                $this->recordCacheHit($cacheKey);
                return $this->retrieveFromCache($cacheKey);
            }

            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;

            $this->storeInCache($cacheKey, $result, $executionTime);
            $this->recordCacheMiss($cacheKey, $executionTime);

            return $result;
        }, ['operation' => 'cache_operation']);
    }

    public function optimizePerformance(array $context = []): void
    {
        $this->security->executeCriticalOperation(function() use ($context) {
            // Monitor and optimize database performance
            $this->optimizeDatabasePerformance();
            
            // Optimize cache usage
            $this->optimizeCacheUsage();
            
            // Manage resource usage
            $this->optimizeResourceUsage();
            
            // Log optimization metrics
            $this->logOptimizationMetrics();
        }, ['operation' => 'performance_optimization']);
    }

    protected function validateCacheOperation(string $key, array $context): void
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new CacheException('Invalid cache key');
        }

        if (!$this->hasStorageCapacity()) {
            throw new PerformanceException('Cache storage capacity exceeded');
        }
    }

    protected function generateCacheKey(string $key, array $context): string
    {
        return hash_hmac(
            'sha256',
            $key . json_encode($context),
            $this->config['cache_key_salt']
        );
    }

    protected function hasValidCache(string $key): bool
    {
        if (!Cache::has($key)) {
            return false;
        }

        $metadata = $this->getCacheMetadata($key);
        return $this->validateCacheMetadata($metadata);
    }

    protected function retrieveFromCache(string $key): mixed
    {
        $encrypted = Cache::get($key);
        return $this->encryption->decrypt($encrypted);
    }

    protected function storeInCache(string $key, mixed $value, float $executionTime): void
    {
        $encrypted = $this->encryption->encrypt(serialize($value));
        
        Cache::put(
            $key,
            $encrypted,
            $this->calculateTTL($executionTime)
        );

        $this->storeCacheMetadata($key, [
            'execution_time' => $executionTime,
            'created_at' => time(),
            'checksum' => hash('sha256', $encrypted)
        ]);
    }

    protected function optimizeDatabasePerformance(): void
    {
        // Monitor query performance
        DB::enableQueryLog();
        
        // Analyze slow queries
        $slowQueries = $this->analyzeQueryPerformance();
        
        // Optimize if needed
        if (!empty($slowQueries)) {
            $this->handleSlowQueries($slowQueries);
        }
        
        // Update query cache
        $this->updateQueryCache();
    }

    protected function optimizeCacheUsage(): void
    {
        // Analyze cache hit rates
        $metrics = $this->analyzeCacheMetrics();
        
        // Clear stale cache entries
        $this->clearStaleCache();
        
        // Adjust cache TTLs based on usage
        $this->optimizeCacheTTLs($metrics);
        
        // Pre-warm frequently accessed cache
        $this->prewarmCriticalCache();
    }

    protected function optimizeResourceUsage(): void
    {
        $usage = [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'connections' => DB::connection()->select('show status like "Threads_connected"')[0]->Value
        ];

        if ($this->exceedsResourceThresholds($usage)) {
            $this->handleResourceStrain($usage);
        }
    }

    protected function analyzeQueryPerformance(): array
    {
        $queryLog = DB::getQueryLog();
        $slowQueries = [];

        foreach ($queryLog as $query) {
            if ($query['time'] > $this->config['slow_query_threshold']) {
                $slowQueries[] = $query;
            }
        }

        return $slowQueries;
    }

    protected function handleSlowQueries(array $slowQueries): void
    {
        foreach ($slowQueries as $query) {
            $this->auditLogger->logSlowQuery([
                'sql' => $query['query'],
                'bindings' => $query['bindings'],
                'time' => $query['time']
            ]);
        }

        if (count($slowQueries) > $this->config['slow_query_threshold_count']) {
            $this->notifyPerformanceIssue('Excessive slow queries detected');
        }
    }

    protected function updateQueryCache(): void
    {
        $frequentQueries = $this->analyzeQueryPatterns();
        
        foreach ($frequentQueries as $query => $frequency) {
            if ($frequency > $this->config['query_cache_threshold']) {
                $this->cacheQueryPlan($query);
            }
        }
    }

    protected function calculateTTL(float $executionTime): int
    {
        // Adjust TTL based on execution time and resource usage
        $baseTTL = $this->config['cache_ttl'];
        $multiplier = min(1, $this->config['max_execution_time'] / $executionTime);
        
        return (int) ($baseTTL * $multiplier);
    }

    protected function logOptimizationMetrics(): void
    {
        $this->auditLogger->logPerformanceMetrics([
            'cache_hit_rate' => $this->calculateCacheHitRate(),
            'average_response_time' => $this->calculateAverageResponseTime(),
            'resource_usage' => $this->getCurrentResourceUsage(),
            'optimization_timestamp' => time()
        ]);
    }
}
