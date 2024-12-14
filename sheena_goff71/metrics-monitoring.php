<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetricsMonitor implements MetricsInterface
{
    private MetricsStore $store;
    private MetricsValidator $validator;
    private SecurityManager $security;
    private PerformanceAnalyzer $analyzer;
    
    public function __construct(
        MetricsStore $store,
        MetricsValidator $validator,
        SecurityManager $security,
        PerformanceAnalyzer $analyzer
    ) {
        $this->store = $store;
        $this->validator = $validator;
        $this->security = $security;
        $this->analyzer = $analyzer;
    }

    public function collectMetrics(Operation $operation): MetricsResult 
    {
        // Generate unique tracking ID
        $metricsId = $this->generateMetricsId($operation);
        
        try {
            // Start transaction for atomic metrics collection
            DB::beginTransaction();
            
            // Validate operation requirements
            $this->validator->validateOperation($operation);
            
            // Collect comprehensive metrics
            $metrics = $this->gatherMetrics($operation);
            
            // Validate collected metrics
            $this->validator->validateMetrics($metrics, $operation->getMetricsRequirements());
            
            // Store metrics with security controls
            $this->storeMetrics($metricsId, $metrics);
            
            // Analyze for critical thresholds
            $this->analyzer->analyzeMetrics($metrics);
            
            DB::commit();
            
            return new MetricsResult(
                success: true,
                metricsId: $metricsId,
                metrics: $metrics
            );
            
        } catch (ValidationException $e) {
            DB::rollBack();
            Log::error('Metrics validation failed', [
                'operation' => $operation->toArray(),
                'error' => $e->getMessage()
            ]);
            throw new MetricsException('Failed to validate metrics: ' . $e->getMessage(), $e);
            
        } catch (SecurityException $e) {
            DB::rollBack();
            Log::critical('Security violation in metrics collection', [
                'operation' => $operation->toArray(),
                'error' => $e->getMessage()
            ]);
            $this->security->handleViolation($e);
            throw new MetricsException('Security violation in metrics collection', $e);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to collect metrics', [
                'operation' => $operation->toArray(),
                'error' => $e->getMessage()
            ]);
            throw new MetricsException('Metrics collection failed: ' . $e->getMessage(), $e);
        }
    }

    private function generateMetricsId(Operation $operation): string
    {
        return sprintf(
            'metrics_%s_%s',
            $operation->getDetails()['type'],
            uniqid('', true)
        );
    }

    private function gatherMetrics(Operation $operation): array
    {
        return [
            'timestamp' => microtime(true),
            'operation' => $operation->getDetails(),
            'system' => [
                'memory' => memory_get_usage(true),
                'cpu' => sys_getloadavg(),
                'connections' => $this->getConnectionMetrics(),
            ],
            'performance' => [
                'response_time' => $this->getResponseTimeMetrics(),
                'throughput' => $this->getThroughputMetrics(),
                'error_rate' => $this->getErrorRateMetrics(),
            ],
            'security' => [
                'access_attempts' => $this->security->getAccessAttempts(),
                'validation_failures' => $this->security->getValidationFailures(),
                'threat_level' => $this->security->getCurrentThreatLevel(),
            ],
        ];
    }

    private function storeMetrics(string $metricsId, array $metrics): void
    {
        // Store encrypted metrics
        $encryptedMetrics = $this->security->encryptMetrics($metrics);
        
        // Store with TTL
        $this->store->store($metricsId, $encryptedMetrics, config('metrics.ttl'));
        
        // Cache frequently accessed metrics
        $this->cacheRecentMetrics($metricsId, $metrics);
    }

    private function getConnectionMetrics(): array
    {
        return [
            'active' => DB::getConnectionCount(),
            'idle' => DB::getIdleConnectionCount(),
            'total' => DB::getTotalConnectionCount(),
        ];
    }

    private function getResponseTimeMetrics(): array
    {
        return [
            'avg' => $this->analyzer->getAverageResponseTime(),
            'max' => $this->analyzer->getMaxResponseTime(),
            'min' => $this->analyzer->getMinResponseTime(),
            'p95' => $this->analyzer->getP95ResponseTime(),
        ];
    }

    private function getThroughputMetrics(): array
    {
        return [
            'requests_per_second' => $this->analyzer->getCurrentThroughput(),
            'bytes_per_second' => $this->analyzer->getCurrentBandwidth(),
            'concurrent_requests' => $this->analyzer->getConcurrentRequests(),
        ];
    }

    private function getErrorRateMetrics(): array
    {
        return [
            'total_errors' => $this->analyzer->getTotalErrors(),
            'error_percentage' => $this->analyzer->getErrorPercentage(),
            'error_types' => $this->analyzer->getErrorBreakdown(),
        ];
    }

    private function cacheRecentMetrics(string $metricsId, array $metrics): void
    {
        $recentKey = 'recent_metrics_' . $metricsId;
        Cache::put($recentKey, [
            'id' => $metricsId,
            'timestamp' => $metrics['timestamp'],
            'summary' => $this->analyzer->summarizeMetrics($metrics)
        ], now()->addMinutes(config('metrics.recent_cache_ttl')));
    }
}
