<?php

namespace App\Core\Monitor;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\MonitorException;

class MonitoringManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const ALERT_LEVELS = ['critical', 'warning', 'info'];
    private const METRIC_TYPES = ['performance', 'security', 'system', 'business'];

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function trackMetric(string $type, string $name, $value): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeTrackMetric($type, $name, $value),
            ['operation' => 'metric_track', 'type' => $type, 'name' => $name]
        );
    }

    public function checkHealth(): HealthReport
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeHealthCheck(),
            ['operation' => 'health_check']
        );
    }

    public function raiseAlert(string $level, string $message, array $context = []): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeAlert($level, $message, $context),
            ['operation' => 'alert_raise', 'level' => $level]
        );
    }

    private function executeTrackMetric(string $type, string $name, $value): void
    {
        if (!in_array($type, self::METRIC_TYPES)) {
            throw new MonitorException('Invalid metric type');
        }

        try {
            $metric = new Metric([
                'type' => $type,
                'name' => $name,
                'value' => $value,
                'timestamp' => now()
            ]);

            // Store metric
            $this->storeMetric($metric);

            // Check thresholds
            $this->checkThresholds($metric);

            // Update aggregations
            $this->updateAggregations($metric);

        } catch (\Exception $e) {
            throw new MonitorException('Failed to track metric: ' . $e->getMessage());
        }
    }

    private function executeHealthCheck(): HealthReport
    {
        try {
            $checks = [
                'system' => $this->checkSystemHealth(),
                'database' => $this->checkDatabaseHealth(),
                'cache' => $this->checkCacheHealth(),
                'security' => $this->checkSecurityHealth()
            ];

            $report = new HealthReport($checks);

            // Cache health status
            $this->cacheHealthStatus($report);

            // Raise alerts if needed
            if (!$report->isHealthy()) {
                $this->raiseHealthAlert($report);
            }

            return $report;

        } catch (\Exception $e) {
            throw new MonitorException('Health check failed: ' . $e->getMessage());
        }
    }

    private function executeAlert(string $level, string $message, array $context): void
    {
        if (!in_array($level, self::ALERT_LEVELS)) {
            throw new MonitorException('Invalid alert level');
        }

        try {
            $alert = new Alert([
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'timestamp' => now()
            ]);

            // Store alert
            $this->storeAlert($alert);

            // Process alert
            $this->processAlert($alert);

            // Notify if critical
            if ($level === 'critical') {
                $this->notifyCriticalAlert($alert);
            }

        } catch (\Exception $e) {
            throw new MonitorException('Failed to raise alert: ' . $e->getMessage());
        }
    }

    private function storeMetric(Metric $metric): void
    {
        MetricHistory::create([
            'type' => $metric->type,
            'name' => $metric->name,
            'value' => $metric->value,
            'timestamp' => $metric->timestamp
        ]);

        // Update current metrics cache
        Cache::tags(['metrics'])->put(
            "metric:{$metric->type}:{$metric->name}",
            $metric,
            $this->config['metrics_ttl']
        );
    }

    private function checkThresholds(Metric $metric): void
    {
        $thresholds = $this->config['thresholds'][$metric->type][$metric->name] ?? null;
        
        if (!$thresholds) {
            return;
        }

        foreach ($thresholds as $level => $threshold) {
            if ($this->isThresholdExceeded($metric->value, $threshold)) {
                $this->raiseThresholdAlert($level, $metric, $threshold);
            }
        }
    }

    private function updateAggregations(Metric $metric): void
    {
        $aggregations = [
            'avg' => $this->calculateAverage($metric),
            'min' => $this->calculateMin($metric),
            'max' => $this->calculateMax($metric)
        ];

        Cache::tags(['metrics', 'aggregations'])->put(
            "aggregation:{$metric->type}:{$metric->name}",
            $aggregations,
            $this->config['aggregation_ttl']
        );
    }

    private function checkSystemHealth(): array
    {
        return [
            'memory' => $this->checkMemoryUsage(),
            'cpu' => $this->checkCpuUsage(),
            'disk' => $this->checkDiskUsage(),
            'load' => $this->checkSystemLoad()
        ];
    }

    private function checkDatabaseHealth(): array
    {
        return [
            'connection' => $this->checkDatabaseConnection(),
            'replication' => $this->checkDatabaseReplication(),
            'performance' => $this->checkDatabasePerformance()
        ];
    }

    private function checkCacheHealth(): array
    {
        return [
            'connection' => $this->checkCacheConnection(),
            'hit_rate' => $this->checkCacheHitRate(),
            'memory' => $this->checkCacheMemory()
        ];
    }

    private function checkSecurityHealth(): array
    {
        return [
            'certificates' => $this->checkCertificates(),
            'encryption' => $this->checkEncryption(),
            'access_control' => $this->checkAccessControl()
        ];
    }

    private function cacheHealthStatus(HealthReport $report): void
    {
        Cache::put(
            'system_health',
            $report,
            $this->config['health_cache_ttl']
        );
    }

    private function raiseHealthAlert(HealthReport $report): void
    {
        $issues = $report->getIssues();
        
        foreach ($issues as $component => $status) {
            $this->raiseAlert(
                'critical',
                "Health check failed for {$component}",
                ['status' => $status]
            );
        }
    }

    private function storeAlert(Alert $alert): void
    {
        AlertHistory::create([
            'level' => $alert->level,
            'message' => $alert->message,
            'context' => $alert->context,
            'timestamp' => $alert->timestamp
        ]);
    }

    private function processAlert(Alert $alert): void
    {
        // Update alert status
        AlertStatus::updateOrCreate(
            [
                'type' => $alert->getType(),
                'component' => $alert->getComponent()
            ],
            [
                'last_alert' => $alert->timestamp,
                'count' => DB::raw('count + 1')
            ]
        );

        // Check for alert correlations
        $this->checkAlertCorrelations($alert);
    }

    private function notifyCriticalAlert(Alert $alert): void
    {
        // Send notifications based on configuration
        if (isset($this->config['notifications'][$alert->getType()])) {
            foreach ($this->config['notifications'][$alert->getType()] as $channel) {
                $this->sendNotification($channel, $alert);
            }
        }
    }

    private function isThresholdExceeded($value, $threshold): bool
    {
        if (is_array($threshold)) {
            return $value < $threshold['min'] || $value > $threshold['max'];
        }
        
        return $value > $threshold;
    }

    private function raiseThresholdAlert(string $level, Metric $metric, $threshold): void
    {
        $this->raiseAlert(
            $level,
            "Threshold exceeded for {$metric->name}",
            [
                'type' => $metric->type,
                'value' => $metric->value,
                'threshold' => $threshold
            ]
        );
    }

    private function calculateAverage(Metric $metric): float
    {
        return MetricHistory::where('type', $metric->type)
                          ->where('name', $metric->name)
                          ->where('timestamp', '>=', now()->subHour())
                          ->avg('value') ?? 0;
    }

    private function calculateMin(Metric $metric): float
    {
        return MetricHistory::where('type', $metric->type)
                          ->where('name', $metric->name)
                          ->where('timestamp', '>=', now()->subHour())
                          ->min('value') ?? 0;
    }

    private function calculateMax(Metric $metric): float
    {
        return MetricHistory::where('type', $metric->type)
                          ->where('name', $metric->name)
                          ->where('timestamp', '>=', now()->subHour())
                          ->max('value') ?? 0;
    }

    private function checkAlertCorrelations(Alert $alert): void
    {
        // Implement alert correlation logic
    }

    private function sendNotification(string $channel, Alert $alert): void
    {
        // Implement notification logic
    }
}