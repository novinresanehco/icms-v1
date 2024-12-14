<?php

namespace App\Core\Monitoring;

use App\Core\Cache\CacheManager;
use App\Exceptions\MonitoringException;
use Illuminate\Support\Facades\Log;

class MonitoringService implements MonitoringInterface
{
    private CacheManager $cache;
    private array $config;
    private array $metrics = [];
    private array $alerts = [];

    public function __construct(CacheManager $cache, array $config)
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    public function startOperation(string $type): string
    {
        $operationId = $this->generateOperationId();
        
        $this->trackOperation($operationId, [
            'type' => $type,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => sys_getloadavg()[0]
        ]);

        return $operationId;
    }

    public function stopOperation(string $operationId): void
    {
        $operation = $this->getOperation($operationId);
        if (!$operation) {
            throw new MonitoringException('Invalid operation ID');
        }

        $endTime = microtime(true);
        $duration = $endTime - $operation['start_time'];
        $memoryUsage = memory_get_usage(true) - $operation['memory_start'];
        $cpuUsage = sys_getloadavg()[0] - $operation['cpu_start'];

        $this->recordMetrics($operationId, [
            'duration' => $duration,
            'memory_usage' => $memoryUsage,
            'cpu_usage' => $cpuUsage
        ]);

        $this->validateOperationMetrics($operationId);
    }

    public function recordMetric(string $name, $value, array $tags = []): void
    {
        $metric = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];

        $this->metrics[] = $metric;
        $this->checkThresholds($metric);
    }

    public function triggerAlert(string $type, array $data, string $severity = 'warning'): void
    {
        $alert = [
            'type' => $type,
            'data' => $data,
            'severity' => $severity,
            'timestamp' => microtime(true)
        ];

        $this->alerts[] = $alert;
        $this->processAlert($alert);
    }

    public function getMetrics(array $filters = []): array
    {
        return array_filter($this->metrics, function($metric) use ($filters) {
            foreach ($filters as $key => $value) {
                if (!isset($metric[$key]) || $metric[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    private function trackOperation(string $operationId, array $data): void
    {
        $key = "operation:$operationId";
        $this->cache->set($key, $data, $this->config['operation_ttl']);
    }

    private function getOperation(string $operationId): ?array
    {
        return $this->cache->get("operation:$operationId");
    }

    private function recordMetrics(string $operationId, array $metrics): void
    {
        foreach ($metrics as $name => $value) {
            $this->recordMetric("operation.$name", $value, ['operation_id' => $operationId]);
        }
    }

    private function validateOperationMetrics(string $operationId): void
    {
        $metrics = $this->getMetrics(['operation_id' => $operationId]);
        
        foreach ($metrics as $metric) {
            if ($this->isMetricCritical($metric)) {
                $this->triggerAlert('critical_metric', [
                    'operation_id' => $operationId,
                    'metric' => $metric
                ], 'critical');
            }
        }
    }

    private function checkThresholds(array $metric): void
    {
        $threshold = $this->config['thresholds'][$metric['name']] ?? null;
        if (!$threshold) {
            return;
        }

        if ($metric['value'] > $threshold['critical']) {
            $this->triggerAlert('threshold_exceeded', [
                'metric' => $metric['name'],
                'value' => $metric['value'],
                'threshold' => $threshold['critical']
            ], 'critical');
        } elseif ($metric['value'] > $threshold['warning']) {
            $this->triggerAlert('threshold_exceeded', [
                'metric' => $metric['name'],
                'value' => $metric['value'],
                'threshold' => $threshold['warning']
            ], 'warning');
        }
    }

    private function processAlert(array $alert): void
    {
        // Log alert
        Log::channel('monitoring')->info('Alert triggered', $alert);

        // Store in cache for quick access
        $key = "alert:" . md5(serialize($alert));
        $this->cache->set($key, $alert, $this->config['alert_ttl']);

        // Execute alert handlers
        if ($alert['severity'] === 'critical') {
            $this->executeCriticalAlertHandlers($alert);
        }
    }

    private function executeCriticalAlertHandlers(array $alert): void
    {
        // Notify emergency contacts
        if (isset($this->config['emergency_contacts'])) {
            foreach ($this->config['emergency_contacts'] as $contact) {
                $this->notifyContact($contact, $alert);
            }
        }

        // Execute emergency procedures if defined
        if (isset($this->config['emergency_procedures'][$alert['type']])) {
            $procedure = $this->config['emergency_procedures'][$alert['type']];
            $procedure($alert);
        }
    }

    private function generateOperationId(): string
    {
        return md5(uniqid(microtime(), true));
    }

    private function isMetricCritical(array $metric): bool
    {
        $threshold = $this->config['thresholds'][$metric['name']]['critical'] ?? null;
        return $threshold !== null && $metric['value'] > $threshold;
    }

    private function notifyContact(array $contact, array $alert): void
    {
        // Implementation depends on notification system
        // Must be handled without throwing exceptions
        try {
            if (isset($contact['email'])) {
                // Send email notification
            }
            if (isset($contact['sms'])) {
                // Send SMS notification
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify contact', [
                'contact' => $contact,
                'alert' => $alert,
                'error' => $e->getMessage()
            ]);
        }
    }
}
