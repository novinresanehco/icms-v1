<?php

namespace App\Core\Monitoring;

use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\DB;

class SystemMonitor implements MonitorInterface
{
    private AuditLogger $audit;
    private CacheManager $cache;
    private SecurityManager $security;
    private array $thresholds;

    private const METRIC_TTL = 3600;
    private const ALERT_THRESHOLD = 0.9;
    private const CRITICAL_THRESHOLD = 0.95;

    public function __construct(
        AuditLogger $audit,
        CacheManager $cache,
        SecurityManager $security,
        array $thresholds
    ) {
        $this->audit = $audit;
        $this->cache = $cache;
        $this->security = $security;
        $this->thresholds = $thresholds;
    }

    public function monitorSystemHealth(): SystemHealth
    {
        try {
            // Collect system metrics
            $metrics = $this->collectSystemMetrics();
            
            // Validate metrics
            $this->validateMetrics($metrics);
            
            // Store metrics
            $this->storeMetrics($metrics);
            
            // Generate health report
            return new SystemHealth(
                $metrics,
                $this->calculateHealthScore($metrics)
            );
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
            throw $e;
        }
    }

    public function trackPerformance(array $metrics): void
    {
        try {
            // Validate performance metrics
            $this->validatePerformanceMetrics($metrics);
            
            // Process metrics
            $processedMetrics = $this->processPerformanceMetrics($metrics);
            
            // Check thresholds
            $this->checkPerformanceThresholds($processedMetrics);
            
            // Store metrics
            $this->storePerformanceMetrics($processedMetrics);
            
        } catch (\Exception $e) {
            $this->handlePerformanceFailure($e, $metrics);
            throw $e;
        }
    }

    private function collectSystemMetrics(): array
    {
        return [
            'cpu' => [
                'usage' => sys_getloadavg()[0] * 100,
                'cores' => $this->getCPUCores()
            ],
            'memory' => [
                'used' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => $this->getMemoryLimit()
            ],
            'storage' => [
                'used' => disk_total_space('/') - disk_free_space('/'),
                'total' => disk_total_space('/')
            ],
            'database' => [
                'connections' => $this->getDatabaseConnections(),
                'queries' => $this->getQueryMetrics()
            ],
            'cache' => [
                'hit_ratio' => $this->getCacheHitRatio(),
                'memory_usage' => $this->getCacheMemoryUsage()
            ],
            'timestamp' => now()
        ];
    }

    private function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $category => $values) {
            if (!isset($this->thresholds[$category])) {
                throw new MonitoringException("Invalid metric category: {$category}");
            }

            foreach ($values as $metric => $value) {
                if (!$this->isValidMetric($category, $metric, $value)) {
                    throw new MonitoringException("Invalid metric value for {$category}.{$metric}");
                }
            }
        }
    }

    private function storeMetrics(array $metrics): void
    {
        $key = $this->generateMetricKey();
        
        $this->cache->set(
            $key,
            $metrics,
            self::METRIC_TTL
        );

        $this->storeMetricsHistory($metrics);
    }

    private function calculateHealthScore(array $metrics): float
    {
        $scores = [];
        
        foreach ($metrics as $category => $values) {
            $scores[$category] = $this->calculateCategoryScore(
                $category,
                $values
            );
        }

        return array_sum($scores) / count($scores);
    }

    private function calculateCategoryScore(string $category, array $values): float
    {
        $categoryThresholds = $this->thresholds[$category];
        $score = 0;
        $count = 0;

        foreach ($values as $metric => $value) {
            if (!isset($categoryThresholds[$metric])) {
                continue;
            }

            $threshold = $categoryThresholds[$metric];
            $score += $value <= $threshold ? 1 : ($threshold / $value);
            $count++;
        }

        return $count > 0 ? $score / $count : 0;
    }

    private function validatePerformanceMetrics(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if (!$this->isValidPerformanceMetric($metric, $value)) {
                throw new MonitoringException("Invalid performance metric: {$metric}");
            }
        }
    }

    private function processPerformanceMetrics(array $metrics): array
    {
        $processed = [];
        
        foreach ($metrics as $metric => $value) {
            $processed[$metric] = [
                'value' => $value,
                'normalized' => $this->normalizeMetric($metric, $value),
                'threshold' => $this->thresholds['performance'][$metric] ?? null,
                'timestamp' => now()
            ];
        }

        return $processed;
    }

    private function checkPerformanceThresholds(array $metrics): void
    {
        foreach ($metrics as $metric => $data) {
            if (!isset($data['threshold'])) {
                continue;
            }

            $ratio = $data['value'] / $data['threshold'];

            if ($ratio >= self::CRITICAL_THRESHOLD) {
                $this->handleCriticalThresholdViolation($metric, $data);
            } elseif ($ratio >= self::ALERT_THRESHOLD) {
                $this->handleThresholdWarning($metric, $data);
            }
        }
    }

    private function handleCriticalThresholdViolation(string $metric, array $data): void
    {
        $this->audit->logCriticalThresholdViolation([
            'metric' => $metric,
            'value' => $data['value'],
            'threshold' => $data['threshold'],
            'timestamp' => $data['timestamp']
        ]);

        $this->security->triggerSecurityAlert(
            'critical_threshold_violation',
            [
                'metric' => $metric,
                'value' => $data['value']
            ]
        );
    }

    private function handleThresholdWarning(string $metric, array $data): void
    {
        $this->audit->logThresholdWarning([
            'metric' => $metric,
            'value' => $data['value'],
            'threshold' => $data['threshold'],
            'timestamp' => $data['timestamp']
        ]);
    }

    private function handleMonitoringFailure(\Exception $e): void
    {
        $this->audit->logMonitoringFailure([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        if ($this->isSystemCritical($e)) {
            $this->handleSystemCriticalFailure($e);
        }
    }

    private function isSystemCritical(\Exception $e): bool
    {
        return $e instanceof SystemCriticalException ||
               $e instanceof DatabaseCorruptionException ||
               $e instanceof SecurityBreachException;
    }

    private function handleSystemCriticalFailure(\Exception $e): void
    {
        // Notify emergency contacts
        $this->notifyEmergencyContacts($e);
        
        // Attempt system recovery
        $this->attemptSystemRecovery();
        
        // Log critical failure
        $this->audit->logCriticalFailure($e);
    }

    private function storeMetricsHistory(array $metrics): void
    {
        DB::transaction(function () use ($metrics) {
            DB::table('system_metrics_history')->insert([
                'metrics' => json_encode($metrics),
                'timestamp' => now()
            ]);
        });
    }

    private function generateMetricKey(): string
    {
        return sprintf(
            'metrics:%s:%s',
            date('YmdH'),
            uniqid()
        );
    }
}
