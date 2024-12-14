<?php

namespace App\Core\Logging;

class LogManager implements LogInterface 
{
    private LogStore $store;
    private SecurityManager $security;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function __construct(
        LogStore $store,
        SecurityManager $security,
        EncryptionService $encryption,
        ValidationService $validator,
        MetricsCollector $metrics,
        AlertSystem $alerts
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
    }

    public function logCriticalEvent(string $type, array $data): void 
    {
        $eventId = uniqid('log_', true);

        try {
            $this->validateLogData($data);
            $this->security->validateContext();

            $enrichedData = $this->enrichLogData($type, $data);
            $secureData = $this->secureLogData($enrichedData);
            
            $this->storeLogEntry($eventId, $type, $secureData);
            $this->processLogEvent($type, $secureData);

        } catch (\Exception $e) {
            $this->handleLogFailure($eventId, $type, $e);
            throw new LoggingException('Logging failed', 0, $e);
        }
    }

    private function validateLogData(array $data): void 
    {
        if (!$this->validator->validateLogData($data)) {
            throw new ValidationException('Invalid log data');
        }
    }

    private function enrichLogData(string $type, array $data): array 
    {
        return array_merge($data, [
            'timestamp' => now(),
            'environment' => config('app.env'),
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'system_load' => sys_getloadavg(),
            'request_context' => [
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'session_id' => session()->getId()
            ],
            'security_context' => $this->security->getContext(),
            'trace_id' => $this->generateTraceId()
        ]);
    }

    private function secureLogData(array $data): array 
    {
        $sensitiveFields = $this->security->getSensitiveFields();
        $securedData = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $securedData[$key] = $this->encryption->encrypt($value);
            } else {
                $securedData[$key] = $value;
            }
        }

        $securedData['_hash'] = $this->generateDataHash($securedData);
        return $securedData;
    }

    private function storeLogEntry(string $eventId, string $type, array $data): void 
    {
        $encryptedLog = $this->encryption->encrypt(json_encode([
            'event_id' => $eventId,
            'type' => $type,
            'data' => $data
        ]));

        if (!$this->store->store($eventId, $encryptedLog)) {
            throw new StorageException('Failed to store log entry');
        }

        $this->security->recordLogEntry($eventId, $type, $data['_hash']);
    }

    private function processLogEvent(string $type, array $data): void 
    {
        if ($this->isSecurityEvent($type)) {
            $this->processSecurityEvent($type, $data);
        }

        if ($this->isPerformanceEvent($type)) {
            $this->processPerformanceEvent($type, $data);
        }

        if ($this->isErrorEvent($type)) {
            $this->processErrorEvent($type, $data);
        }

        $this->metrics->recordLogEvent($type, $data);
    }

    private function processSecurityEvent(string $type, array $data): void 
    {
        $securityEvent = new SecurityEvent($type, $data);

        if ($securityEvent->isCritical()) {
            $this->security->handleCriticalEvent($securityEvent);
            $this->alerts->triggerSecurityAlert($securityEvent);
        }

        $this->security->trackSecurityEvent($securityEvent);
    }

    private function processPerformanceEvent(string $type, array $data): void 
    {
        $performanceEvent = new PerformanceEvent($type, $data);

        if ($performanceEvent->isThresholdExceeded()) {
            $this->alerts->triggerPerformanceAlert($performanceEvent);
        }

        $this->metrics->trackPerformanceMetric($performanceEvent);
    }

    private function processErrorEvent(string $type, array $data): void 
    {
        $errorEvent = new ErrorEvent($type, $data);

        if ($errorEvent->isCritical()) {
            $this->alerts->triggerErrorAlert($errorEvent);
            $this->security->handleCriticalError($errorEvent);
        }

        $this->metrics->trackErrorMetric($errorEvent);
    }

    private function handleLogFailure(string $eventId, string $type, \Exception $e): void 
    {
        try {
            $failureData = [
                'event_id' => $eventId,
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()
            ];

            $this->store->storeFailure($eventId, $failureData);
            $this->alerts->triggerLoggingFailure($failureData);

            if ($e instanceof SecurityException) {
                $this->security->handleSecurityIncident($eventId, $e);
            }

        } catch (\Exception $fallbackError) {
            $this->alerts->triggerCriticalFailure($fallbackError);
            $this->security->initiateEmergencyProtocol();
        }
    }

    private function generateTraceId(): string 
    {
        return bin2hex(random_bytes(16));
    }

    private function generateDataHash(array $data): string 
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            $this->security->getSecretKey()
        );
    }

    private function isSecurityEvent(string $type): bool 
    {
        return str_starts_with($type, 'security.');
    }

    private function isPerformanceEvent(string $type): bool 
    {
        return str_starts_with($type, 'performance.');
    }

    private function isErrorEvent(string $type): bool 
    {
        return str_starts_with($type, 'error.');
    }
}
