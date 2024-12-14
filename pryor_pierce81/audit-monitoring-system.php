<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Services\{
    EncryptionService,
    ValidationService,
    NotificationService
};
use App\Core\Exceptions\{
    MonitoringException,
    SecurityException
};

class SystemMonitor
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private NotificationService $notifier;
    private array $config;
    private array $metrics = [];

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        NotificationService $notifier,
        array $config
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->notifier = $notifier;
        $this->config = $config;
    }

    public function monitorOperation(callable $operation, array $context): mixed
    {
        $monitoringId = $this->initializeMonitoring($context);

        try {
            $startTime = microtime(true);
            $this->trackResourceUsage('start');
            
            $result = $operation();
            
            $this->trackResourceUsage('end');
            $executionTime = microtime(true) - $startTime;
            
            $this->recordSuccess($monitoringId, $context, $executionTime);
            
            return $result;

        } catch (\Throwable $e) {
            $this->handleFailure($monitoringId, $e, $context);
            throw $e;
        } finally {
            $this->finalizeMonitoring($monitoringId);
        }
    }

    public function trackSecurityEvent(array $event): void
    {
        if (!$this->validator->validateSecurityEvent($event)) {
            throw new SecurityException('Invalid security event data');
        }

        $encryptedEvent = $this->encryption->encrypt(json_encode($event));
        
        DB::table('security_events')->insert([
            'event_data' => $encryptedEvent,
            'severity' => $event['severity'],
            'timestamp' => now(),
            'signature' => $this->generateEventSignature($event)
        ]);

        if ($this->isHighSeverity($event)) {
            $this->notifySecurityTeam($event);
        }

        $this->updateSecurityMetrics($event);
    }

    public function trackPerformanceMetrics(array $metrics): void
    {
        $validatedMetrics = $this->validator->validateMetrics($metrics);
        
        foreach ($validatedMetrics as $metric => $value) {
            $this->metrics[$metric] = $value;
            
            if ($this->exceedsThreshold($metric, $value)) {
                $this->handleThresholdViolation($metric, $value);
            }
        }

        $this->persistMetrics($validatedMetrics);
    }

    protected function initializeMonitoring(array $context): string
    {
        $monitoringId = $this->generateMonitoringId();
        
        Cache::put(
            "monitoring:{$monitoringId}",
            [
                'context' => $context,
                'start_time' => microtime(true),
                'resources' => $this->getCurrentResourceUsage()
            ],
            $this->config['monitoring_ttl']
        );

        return $monitoringId;
    }

    protected function trackResourceUsage(string $phase): void
    {
        $usage = [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'connections' => DB::connection()->select('show status like "Threads_connected"')[0]->Value,
            'timestamp' => microtime(true)
        ];

        $this->metrics["resource_usage_{$phase}"] = $usage;

        if ($this->detectResourceStrain($usage)) {
            $this->handleResourceStrain($usage);
        }
    }

    protected function recordSuccess(string $monitoringId, array $context, float $executionTime): void
    {
        $metrics = [
            'execution_time' => $executionTime,
            'resource_usage' => $this->calculateResourceUsage(),
            'cache_hits' => $this->getCacheMetrics(),
            'query_count' => $this->getQueryMetrics()
        ];

        DB::table('operation_metrics')->insert([
            'monitoring_id' => $monitoringId,
            'context' => json_encode($context),
            'metrics' => json_encode($metrics),
            'timestamp' => now()
        ]);

        $this->updateAggregateMetrics($metrics);
    }

    protected function handleFailure(string $monitoringId, \Throwable $e, array $context): void
    {
        $failureData = [
            'monitoring_id' => $monitoringId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context,
            'metrics' => $this->metrics,
            'timestamp' => now()
        ];

        DB::table('operation_failures')->insert([
            'data' => $this->encryption->encrypt(json_encode($failureData)),
            'severity' => $this->calculateFailureSeverity($e)
        ]);

        if ($this->isHighSeverityFailure($e)) {
            $this->notifier->sendEmergencyAlert($failureData);
        }

        $this->updateFailureMetrics($failureData);
    }

    protected function finalizeMonitoring(string $monitoringId): void
    {
        $metrics = Cache::get("monitoring:{$monitoringId}");
        $this->persistFinalMetrics($monitoringId, $metrics);
        Cache::forget("monitoring:{$monitoringId}");
    }

    protected function detectResourceStrain(array $usage): bool
    {
        return $usage['memory'] > $this->config['memory_threshold'] ||
               $usage['cpu'] > $this->config['cpu_threshold'] ||
               $usage['connections'] > $this->config['connection_threshold'];
    }

    private function generateMonitoringId(): string
    {
        return hash('sha256', uniqid('monitor_', true));
    }

    private function generateEventSignature(array $event): string
    {
        return hash_hmac('sha256', json_encode($event), $this->config['signature_key']);
    }
}
