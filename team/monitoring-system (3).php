<?php

namespace App\Core\Monitor;

/**
 * Critical system monitoring and control
 */
class PerformanceMonitor
{
    private const CRITICAL_CPU_THRESHOLD = 70;
    private const CRITICAL_MEMORY_THRESHOLD = 80;
    private const MAX_RESPONSE_TIME = 200;

    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private Logger $logger;

    public function startOperation(Operation $operation): TrackingContext
    {
        // Verify system health
        $this->verifySystemHealth();
        
        // Start metrics collection
        return $this->metrics->startTracking([
            'operation' => $operation->getType(),
            'timestamp' => microtime(true),
            'resources' => $this->getCurrentResources()
        ]);
    }

    public function endOperation(TrackingContext $context): void
    {
        // Calculate metrics
        $duration = microtime(true) - $context->startTime;
        $resources = $this->getResourceUsage($context);
        
        // Store metrics
        $this->metrics->record([
            'duration' => $duration,
            'memory' => $resources['memory'],
            'cpu' => $resources['cpu']
        ]);

        // Check thresholds
        if ($duration > self::MAX_RESPONSE_TIME) {
            $this->handlePerformanceIssue($context, $duration);
        }

        if ($resources['cpu'] > self::CRITICAL_CPU_THRESHOLD ||
            $resources['memory'] > self::CRITICAL_MEMORY_THRESHOLD) {
            $this->handleResourceIssue($resources);
        }
    }

    public function isSystemHealthy(): bool
    {
        $health = $this->getSystemHealth();
        
        return
            $health['cpu'] < self::CRITICAL_CPU_THRESHOLD &&
            $health['memory'] < self::CRITICAL_MEMORY_THRESHOLD &&
            $health['disk_space'] > 20 &&
            $this->isDatabaseHealthy() &&
            $this->isCacheHealthy();
    }

    private function handlePerformanceIssue(TrackingContext $context, float $duration): void
    {
        $this->logger->warning('Performance threshold exceeded', [
            'operation' => $context->operation,
            'duration' => $duration,
            'threshold' => self::MAX_RESPONSE_TIME
        ]);

        $this->alerts->sendPerformanceAlert([
            'type' => 'performance_degradation',
            'duration' => $duration,
            'context' => $context
        ]);
    }

    private function handleResourceIssue(array $resources): void
    {
        $this->logger->error('Resource usage critical', [
            'cpu' => $resources['cpu'],
            'memory' => $resources['memory']
        ]);

        $this->alerts->sendResourceAlert([
            'type' => 'resource_critical',
            'resources' => $resources
        ]);
    }
}
