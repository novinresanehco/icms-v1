<?php

namespace App\Http\Middleware;

use App\Core\Monitoring\PerformanceMonitor;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditLogger;
use Illuminate\Http\Request;

class PerformanceMiddleware
{
    private PerformanceMonitor $monitor;
    private CacheManager $cache;
    private AuditLogger $audit;

    private const PERFORMANCE_THRESHOLD = [
        'response_time' => 200, // milliseconds
        'memory_usage' => 83886080, // 80MB
        'cpu_usage' => 70 // percentage
    ];

    public function __construct(
        PerformanceMonitor $monitor,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function handle(Request $request, \Closure $next)
    {
        // Start monitoring
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $monitoringId = $this->monitor->startRequest($request);

        try {
            // Execute request
            $response = $next($request);
            
            // Collect metrics
            $this->collectMetrics($request, $startTime, $startMemory);
            
            // Validate performance
            $this->validatePerformance($monitoringId);
            
            // Add performance headers
            $response = $this->addPerformanceHeaders($response, $monitoringId);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->handlePerformanceFailure($e, $monitoringId);
            throw $e;
        } finally {
            // End monitoring
            $this->monitor->endRequest($monitoringId);
        }
    }

    private function collectMetrics(Request $request, float $startTime, int $startMemory): void
    {
        $metrics = [
            'response_time' => (microtime(true) - $startTime) * 1000,
            'memory_usage' => memory_get_usage() - $startMemory,
            'cpu_usage' => sys_getloadavg()[0] * 100,
            'timestamp' => now()
        ];

        $this->monitor->recordMetrics($metrics);
        
        if ($this->exceedsThresholds($metrics)) {
            $this->handlePerformanceIssue($metrics, $request);
        }
    }

    private function validatePerformance(string $monitoringId): void
    {
        $metrics = $this->monitor->getRequestMetrics($monitoringId);
        
        if ($metrics['response_time'] > self::PERFORMANCE_THRESHOLD['response_time']) {
            throw new PerformanceException('Response time exceeds threshold');
        }

        if ($metrics['memory_usage'] > self::PERFORMANCE_THRESHOLD['memory_usage']) {
            throw new PerformanceException('Memory usage exceeds threshold');
        }

        if ($metrics['cpu_usage'] > self::PERFORMANCE_THRESHOLD['cpu_usage']) {
            throw new PerformanceException('CPU usage exceeds threshold');
        }
    }

    private function addPerformanceHeaders($response, string $monitoringId): mixed
    {
        $metrics = $this->monitor->getRequestMetrics($monitoringId);
        
        return $response->withHeaders([
            'X-Response-Time' => round($metrics['response_time'], 2) . 'ms',
            'X-Memory-Usage' => round($metrics['memory_usage'] / 1024 / 1024, 2) . 'MB',
            'X-CPU-Usage' => round($metrics['cpu_usage'], 2) . '%'
        ]);
    }

    private function exceedsThresholds(array $metrics): bool
    {
        return $metrics['response_time'] > self::PERFORMANCE_THRESHOLD['response_time'] ||
               $metrics['memory_usage'] > self::PERFORMANCE_THRESHOLD['memory_usage'] ||
               $metrics['cpu_usage'] > self::PERFORMANCE_THRESHOLD['cpu_usage'];
    }

    private function handlePerformanceIssue(array $metrics, Request $request): void
    {
        $this->audit->logPerformanceIssue([
            'request_id' => $request->header('x-request-id'),
            'uri' => $request->getRequestUri(),
            'method' => $request->getMethod(),
            'metrics' => $metrics,
            'threshold' => self::PERFORMANCE_THRESHOLD
        ]);

        $this->monitor->triggerPerformanceAlert($metrics);
    }

    private function handlePerformanceFailure(\Exception $e, string $monitoringId): void
    {
        $this->audit->logPerformanceFailure($e, [
            'monitoring_id' => $monitoringId,
            'metrics' => $this->monitor->getRequestMetrics($monitoringId),
            'timestamp' => now()
        ]);
    }
}
