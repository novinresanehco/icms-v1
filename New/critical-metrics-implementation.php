<?php

namespace App\Core\Monitoring;

class MetricsCollector implements MetricsCollectorInterface
{
    private MetricsStore $store;
    private SecurityManager $security;
    private AlertManager $alerts;
    private MetricsConfig $config;

    public function record(string $metric, mixed $value): void
    {
        try {
            $data = [
                'metric' => $metric,
                'value' => $value,
                'timestamp' => microtime(true),
                'context' => $this->getContext()
            ];

            $this->store->record($data);
            
            $this->checkThresholds($metric, $value);
            
        } catch (\Exception $e) {
            $this->security->handleMetricsFailure($e);
        }
    }

    public function startOperation(string $type): string
    {
        $id = $this->generateOperationId();
        
        $this->record("operation.start.{$type}", [
            'operation_id' => $id,
            'type' => $type,
            'start_time' => microtime(true)
        ]);

        return $id;
    }

    public function endOperation(string $id, bool $success = true): void
    {
        $operation = $this->store->getOperation($id);
        
        if (!$operation) {
            return;
        }

        $duration = microtime(true) - $operation['start_time'];
        
        $this->record("operation.end.{$operation['type']}", [
            'operation_id' => $id,
            'duration' => $duration,
            'success' => $success
        ]);
    }

    public function incrementCounter(string $metric): int
    {
        $value = $this->store->increment($metric);
        
        $this->checkThresholds($metric, $value);
        
        return $value;
    }

    public function recordValue(string $metric, float $value): void
    {
        $this->record($metric, $value);
    }

    private function checkThresholds(string $metric, mixed $value): void
    {
        $thresholds = $this->config->getThresholds($metric);
        
        foreach ($thresholds as $threshold) {
            if ($this->isThresholdExceeded($value, $threshold)) {
                $this->handleThresholdExceeded($metric, $value, $threshold);
            }
        }
    }

    private function handleThresholdExceeded(string $metric, mixed $value, array $threshold): void
    {
        $alert = new MetricAlert($metric, $value, $threshold);
        
        $this->alerts->trigger($alert);
        
        if ($threshold['critical']) {
            $this->security->handleCriticalMetric($metric, $value);
        }
    }

    private function getContext(): array
    {
        return [
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'url' => request()->url(),
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0]
        ];
    }

    private function generateOperationId(): string
    {
        return md5(uniqid('operation', true));
    }
}

class AlertManager implements AlertManagerInterface
{
    private NotificationManager $notifications;
    private SecurityManager $security;
    private AlertConfig $config;
    
    public function trigger(Alert $alert): void
    {
        try {
            // Log the alert
            $this->logAlert($alert);
            
            // Notify relevant parties
            $this->notify($alert);
            
            // Execute alert-specific protocols
            $this->executeAlertProtocols($alert);
            
        } catch (\Exception $e) {
            $this->security->handleAlertFailure($e, $alert);
        }
    }

    private function logAlert(Alert $alert): void
    {
        Log::critical('System alert triggered', [
            'type' => get_class($alert),
            'data' => $alert->getData(),
            'timestamp' => time(),
            'context' => $this->getAlertContext()
        ]);
    }

    private function notify(Alert $alert): void
    {
        $recipients = $this->config->getAlertRecipients($alert);
        
        foreach ($recipients as $recipient) {
            $this->notifications->send(
                $recipient,
                new SystemAlertNotification($alert)
            );
        }
    }

    private function executeAlertProtocols(Alert $alert): void
    {
        $protocols = $this->config->getAlertProtocols($alert);
        
        foreach ($protocols as $protocol) {
            $this->executeProtocol($protocol, $alert);
        }
    }

    private function executeProtocol(string $protocol, Alert $alert): void
    {
        match($protocol) {
            'shutdown' => $this->executeShutdownProtocol($alert),
            'backup' => $this->executeBackupProtocol($alert),
            'scale' => $this->executeScaleProtocol($alert),
            default => null
        };
    }

    private function getAlertContext(): array
    {
        return [
            'system_status' => $this->getSystemStatus(),
            'performance_metrics' => $this->getPerformanceMetrics(),
            'security_status' => $this->getSecurityStatus()
        ];
    }
}
