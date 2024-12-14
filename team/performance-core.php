<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;

class PerformanceManager
{
    private SecurityManager $security;
    private MonitoringService $monitor;
    private CacheService $cache;
    
    private const PERFORMANCE_THRESHOLDS = [
        'response_time' => 200, // milliseconds
        'memory_usage' => 128 * 1024 * 1024, // 128MB
        'query_time' => 50, // milliseconds
        'cache_hit_ratio' => 0.8 // 80%
    ];

    public function __construct(
        SecurityManager $security,
        MonitoringService $monitor,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
    }

    /**
     * Execute optimized operation with monitoring
     */
    public function executeOptimized(string $key, callable $operation, array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation($key);
        
        try {
            // Check cache first
            if ($cached = $this->cache->get($key)) {
                $this->monitor->recordCacheHit($key);
                return $cached;
            }

            // Execute with monitoring
            $result = $this->monitor->trackExecution($operationId, function() use ($operation) {
                return $operation();
            });

            // Validate performance
            $this->validatePerformance($operationId);

            // Cache result
            $this->cache->set($key, $result);

            return $result;

        } catch (\Throwable $e) {
            $this->handlePerformanceFailure($e, $key, $context);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Optimize database queries
     */
    public function optimizeQueries(): void
    {
        DB::beginTransaction();
        
        try {
            // Analyze tables
            DB::unprepared('ANALYZE TABLE users, contents, media');
            
            // Update statistics
            DB::unprepared('OPTIMIZE TABLE users, contents, media');
            
            // Cache query plans
            $this->cacheQueryPlans();
            
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleOptimizationFailure($e);
        }
    }

    /**
     * Cache warmup for critical paths
     */
    public function warmupCache(): void
    {
        try {
            // Critical data warmup
            $this->warmupCriticalData();
            
            // Query cache warmup
            $this->warmupQueryCache();
            
            // Route cache warmup
            $this->warmupRouteCache();
            
            // Config cache warmup
            $this->warmupConfigCache();
        } catch (\Throwable $e) {
            $this->handleWarmupFailure($e);
        }
    }

    /**
     * Validate performance metrics
     */
    private function validatePerformance(string $operationId): void
    {
        $metrics = $this->monitor->getMetrics($operationId);
        
        foreach (self::PERFORMANCE_THRESHOLDS as $metric => $threshold) {
            if (($metrics[$metric] ?? 0) > $threshold) {
                throw new PerformanceException("Performance threshold exceeded for {$metric}");
            }
        }
    }

    /**
     * Handle performance-related failures
     */
    private function handlePerformanceFailure(\Throwable $e, string $key, array $context): void
    {
        Log::error('Performance failure', [
            'key' => $key,
            'context' => $context,
            'error' => $e->getMessage(),
            'metrics' => $this->monitor->getMetrics($key)
        ]);

        $this->security->notifyAdministrators('performance_failure', [
            'error' => $e->getMessage(),
            'key' => $key
        ]);
    }

    /**
     * Cache query execution plans
     */
    private function cacheQueryPlans(): void
    {
        $criticalQueries = $this->getCriticalQueries();
        
        foreach ($criticalQueries as $query) {
            DB::unprepared("EXPLAIN ANALYZE {$query}");
        }
    }

    /**
     * Warmup critical data cache
     */
    private function warmupCriticalData(): void
    {
        // Cache critical user data
        $this->cache->remember('users.active', function() {
            return DB::table('users')->where('active', true)->get();
        });

        // Cache permissions
        $this->cache->remember('permissions.all', function() {
            return DB::table('permissions')->get();
        });

        // Cache common content
        $this->cache->remember('content.published', function() {
            return DB::table('contents')
                ->where('status', 'published')
                ->orderBy('updated_at', 'desc')
                ->limit(100)
                ->get();
        });
    }

    /**
     * Warmup query cache
     */
    private function warmupQueryCache(): void
    {
        foreach ($this->getCriticalQueries() as $key => $query) {
            $this->cache->remember("query.{$key}", function() use ($query) {
                return DB::select($query);
            });
        }
    }

    /**
     * Get list of critical queries
     */
    private function getCriticalQueries(): array
    {
        return [
            'active_users' => 'SELECT id, name, email FROM users WHERE active = true',
            'recent_content' => 'SELECT id, title, status FROM contents ORDER BY created_at DESC LIMIT 50',
            'system_stats' => 'SELECT COUNT(*) as count, status FROM contents GROUP BY status'
        ];
    }

    /**
     * Warmup route cache
     */
    private function warmupRouteCache(): void
    {
        \Artisan::call('route:cache');
    }

    /**
     * Warmup config cache
     */
    private function warmupConfigCache(): void
    {
        \Artisan::call('config:cache');
    }

    /**
     * Handle cache warmup failures
     */
    private function handleWarmupFailure(\Throwable $e): void
    {
        Log::error('Cache warmup failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('cache_warmup_failure', [
            'error' => $e->getMessage()
        ]);
    }

    /**
     * Handle query optimization failures
     */
    private function handleOptimizationFailure(\Throwable $e): void
    {
        Log::error('Query optimization failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('optimization_failure', [
            'error' => $e->getMessage()
        ]);
    }
}
