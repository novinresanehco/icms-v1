<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Support\Facades\Storage;

class LogManager implements LogInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private array $config;
    private array $handlers = [];

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeHandlers();
    }

    public function logCriticalEvent(string $event, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('critical_logging');
        
        try {
            $entry = $this->prepareCriticalLogEntry($event, $data);
            
            $this->validateLogEntry($entry);
            
            foreach ($this->handlers as $handler) {
                $handler->handleCriticalLog($entry);
            }
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleLoggingFailure($e, $event, $data);
            throw new LoggingException('Critical log failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function logSecurityEvent(string $event, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('security_logging');
        
        try {
            $entry = $this->prepareSecurityLogEntry($event, $data);
            
            $this->validateLogEntry($entry);
            
            foreach ($this->handlers as $handler) {
                $handler->handleSecurityLog($entry);
            }
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleLoggingFailure($e, $event, $data);
            throw new LoggingException('Security log failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function prepareCriticalLogEntry(string $event, array $data): array
    {
        return [
            'type' => 'critical',
            'event' => $event,
            'data' => $this->sanitizeLogData($data),
            'context' => [
                'user_id' => $this->security->getUserId(),
                'ip' => $this->security->getIpAddress(),
                'timestamp' => microtime(true),
                'process_id' => getmypid(),
                'memory_usage' => memory_get_usage(true)
            ],
            'system_state' => $this->monitor->captureSystemState()
        ];
    }

    private function prepareSecurityLogEntry(string $event, array $data): array
    {
        return [
            'type' => 'security',
            'event' => $event,
            'data' => $this->sanitizeLogData($data),
            'context' => [
                'user_id' => $this->security->getUserId(),
                'ip' => $this->security->getIpAddress(),
                'session_id' => $this->security->getSessionId(),
                'timestamp' => microtime(true)
            ],
            'security_context' => $this->security->getSecurityContext()
        ];
    }

    private function validateLogEntry(array $entry): void
    {
        if (!isset($entry['event']) || !isset($entry['data'])) {
            throw new LogValidationException('Invalid log entry structure');
        }

        if (!$this->validateLogLevel($entry['type'])) {
            throw new LogValidationException('Invalid log level');
        }
    }

    private function sanitizeLogData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if ($this->isSensitiveField($key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        
        return $sanitized;
    }

    private function handleLoggingFailure(
        \Exception $e,
        string $event,
        array $data
    ): void {
        try {
            $emergencyLog = [
                'error' => $e->getMessage(),
                'event' => $event,
                'data' => $this->sanitizeLogData($data),
                'timestamp' => microtime(true)
            ];
            
            Storage::append(
                $this->config['emergency_log_path'],
                json_encode($emergencyLog)
            );
            
        } catch (\Exception $emergencyException) {
            // Last resort logging to system log
            error_log(
                "Critical logging failure: {$e->getMessage()} - " .
                "Emergency logging failed: {$emergencyException->getMessage()}"
            );
        }
    }

    private function initializeHandlers(): void
    {
        foreach ($this->config['handlers'] as $handler) {
            $this->handlers[] = new $handler($this->config);
        }
    }
}
