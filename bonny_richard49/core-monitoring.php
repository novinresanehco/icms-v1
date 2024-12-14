<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Log, Cache, DB};

class SystemMonitor
{
    private array $thresholds = [
        'response_time' => 200,
        'memory_limit' => 128,
        'error_rate' => 0.01
    ];

    private MetricsCollector $metrics;
    private AlertManager $alerts;

    public function captureMetrics(string $operation, float $startTime): void
    {
        $metrics = [
            'duration' => microtime(true) - $startTime,
            'memory' => memory_get_usage(true),
            'sql_queries' => DB::getQueryLog(),
            'timestamp' => now()
        ];

        $this->metrics->record($operation, $metrics);
        $this->checkThresholds($operation, $metrics);
    }

    private function checkThresholds(string $operation, array $metrics): void
    {
        if ($metrics['duration'] > $this->thresholds['response_time']) {
            $this->alerts->performance($operation, $metrics);
        }

        if ($metrics['memory'] > $this->thresholds['memory_limit'] * 1024 * 1024) {
            $this->alerts->resource($operation, $metrics);
        }
    }
}

class ErrorHandler
{
    private AlertManager $alerts;
    private SystemMonitor $monitor;
    
    public function handleException(\Throwable $e): void
    {
        $context = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->monitor->getCurrentMetrics()
        ];

        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e, $context);
        } elseif ($e instanceof DatabaseException) {
            $this->handleDatabaseException($e, $context);
        } else {
            $this->handleGeneralException($e, $context);
        }
    }

    private function handleSecurityException(SecurityException $e, array $context): void
    {
        Log::critical('Security violation detected', $context);
        $this->alerts->security($e, $context);
    }

    private function handleDatabaseException(DatabaseException $e, array $context): void
    {
        Log::error('Database operation failed', $context);
        $this->alerts->database($e, $context);
        
        if ($this->isDeadlock($e)) {
            $this->handleDeadlock($context);
        }
    }

    private function isDeadlock(\Exception $e): bool
    {
        return strpos($e->getMessage(), 'Deadlock') !== false;
    }

    private function handleDeadlock(array $context): void
    {
        DB::rollBack();
        Cache::tags(['transactions'])->flush();
        Log::warning('Deadlock detected and handled', $context);
    }
}

class AlertManager
{
    private array $config;
    private NotificationService $notifications;

    public function security(\Exception $e, array $context): void
    {
        $this->notify('security', [
            'severity' => 'critical',
            'exception' => $e,
            'context' => $context
        ]);
    }

    public function performance(string $operation, array $metrics): void
    {
        $this->notify('performance', [
            'severity' => 'warning',
            'operation' => $operation,
            'metrics' => $metrics
        ]);
    }

    private function notify(string $type, array $data): void
    {
        $this->notifications->send(
            $this->config["alerts.$type.channels"],
            $this->formatAlert($type, $data)
        );
    }

    private function formatAlert(string $type, array $data): array
    {
        return [
            'type' => $type,
            'timestamp' => now(),
            'data' => $data,
            'environment' => app()->environment()
        ];
    }
}

class FailsafeMode
{
    private SystemMonitor $monitor;
    private AlertManager $alerts;

    public function activate(string $reason): void
    {
        Cache::set('system.failsafe', true);
        
        Log::emergency('Failsafe mode activated', [
            'reason' => $reason,
            'metrics' => $this->monitor->getCurrentMetrics()
        ]);

        $this->alerts->notify('failsafe', [
            'status' => 'activated',
            'reason' => $reason
        ]);
    }

    public function isActive(): bool
    {
        return Cache::get('system.failsafe', false);
    }

    public function canDeactivate(): bool
    {
        $metrics = $this->monitor->getCurrentMetrics();
        return $metrics['error_rate'] < 0.01 
            && $metrics['response_time'] < 200
            && $metrics['memory'] < 128 * 1024 * 1024;
    }
}
