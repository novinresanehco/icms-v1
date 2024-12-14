// app/Core/Error/ExceptionManager.php
<?php

namespace App\Core\Error;

use App\Core\Security\SecurityKernel;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Logging\LogManager;

class ExceptionManager implements ExceptionHandlerInterface
{
    private SecurityKernel $security;
    private MetricsCollector $metrics;
    private LogManager $logger;
    private array $config;
    private array $handlers = [];

    public function handleException(\Throwable $e, array $context = []): void
    {
        $startTime = microtime(true);
        $exceptionId = $this->generateExceptionId();

        try {
            // Initial security validation
            $this->validateContext($context);

            // Execute secure exception handling
            $this->security->executeSecure(function() use ($e, $context, $exceptionId) {
                $this->executeExceptionHandling($e, $context, $exceptionId);
            });

            // Record metrics
            $this->recordExceptionMetrics($e, $exceptionId, $startTime);

        } catch (\Throwable $internalException) {
            $this->handleInternalFailure($internalException, $e, $exceptionId);
        }
    }

    private function executeExceptionHandling(\Throwable $e, array $context, string $exceptionId): void
    {
        // Determine severity level
        $severity = $this->determineSeverity($e);

        // Log exception with full context
        $this->logException($e, $context, $exceptionId, $severity);

        // Execute appropriate handlers
        foreach ($this->getHandlersForException($e) as $handler) {
            $handler->handle($e, $context, $exceptionId);
        }

        // Notify monitoring systems
        $this->notifyMonitoringSystems($e, $exceptionId, $severity);

        // Store exception data for analysis
        $this->storeExceptionData($e, $context, $exceptionId, $severity);
    }

    private function validateContext(array $context): void
    {
        if (!isset($context['user_id']) || !isset($context['request_id'])) {
            throw new InvalidContextException('Missing required context information');
        }
    }

    private function determineSeverity(\Throwable $e): string
    {
        return match(true) {
            $e instanceof SecurityException => 'critical',
            $e instanceof DatabaseException => 'critical',
            $e instanceof ValidationException => 'error',
            $e instanceof BusinessException => 'warning',
            default => 'error'
        };
    }

    private function logException(
        \Throwable $e,
        array $context,
        string $exceptionId,
        string $severity
    ): void {
        $this->logger->log($severity, 'Exception occurred', [
            'exception_id' => $exceptionId,
            'exception' => [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'context' => $context,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true)
        ]);
    }

    private function getHandlersForException(\Throwable $e): array
    {
        return array_filter($this->handlers, function($handler) use ($e) {
            return $handler->canHandle($e);
        });
    }

    private function notifyMonitoringSystems(
        \Throwable $e,
        string $exceptionId,
        string $severity
    ): void {
        if ($severity === 'critical') {
            event(new CriticalExceptionEvent($e, $exceptionId));
        }

        $this->metrics->increment('exception.occurred', [
            'type' => get_class($e),
            'severity' => $severity
        ]);
    }

    private function storeExceptionData(
        \Throwable $e,
        array $context,
        string $exceptionId,
        string $severity
    ): void {
        ExceptionRecord::create([
            'exception_id' => $exceptionId,
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'severity' => $severity,
            'context' => json_encode($context),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'created_at' => now()
        ]);
    }

    private function handleInternalFailure(
        \Throwable $internal,
        \Throwable $original,
        string $exceptionId
    ): void {
        Log::emergency('Exception handler failed', [
            'internal_exception' => [
                'message' => $internal->getMessage(),
                'trace' => $internal->getTraceAsString()
            ],
            'original_exception' => [
                'message' => $original->getMessage(),
                'trace' => $original->getTraceAsString()
            ],
            'exception_id' => $exceptionId
        ]);

        // Notify emergency contacts
        $this->notifyEmergencyContacts($internal, $original, $exceptionId);
    }

    private function recordExceptionMetrics(
        \Throwable $e,
        string $exceptionId,
        float $startTime
    ): void {
        $this->metrics->timing('exception.handling_time', microtime(true) - $startTime, [
            'type' => get_class($e),
            'exception_id' => $exceptionId
        ]);
    }

    private function generateExceptionId(): string
    {
        return uniqid('exc_', true);
    }

    private function notifyEmergencyContacts(
        \Throwable $internal,
        \Throwable $original,
        string $exceptionId
    ): void {
        foreach ($this->config['emergency_contacts'] as $contact) {
            EmergencyNotification::dispatch($contact, [
                'internal_exception' => $internal,
                'original_exception' => $original,
                'exception_id' => $exceptionId
            ]);
        }
    }
}

// app/Core/Logging/LogManager.php
class LogManager implements LogInterface
{
    private SecurityKernel $security;
    private array $config;
    private array $handlers;

    public function log(string $level, string $message, array $context = []): void
    {
        $this->security->executeSecure(function() use ($level, $message, $context) {
            $this->executeLogging($level, $message, $context);
        });
    }

    private function executeLogging(string $level, string $message, array $context): void
    {
        $logEntry = $this->createLogEntry($level, $message, $context);
        
        foreach ($this->getHandlersForLevel($level) as $handler) {
            $handler->handle($logEntry);
        }

        if ($this->shouldNotify($level)) {
            $this->notifyMonitoring($logEntry);
        }
    }

    private function createLogEntry(string $level, string $message, array $context): array
    {
        return [
            'id' => uniqid('log_', true),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true),
            'user_id' => auth()->id(),
            'request_id' => request()->id(),
            'ip' => request()->ip()
        ];
    }

    private function getHandlersForLevel(string $level): array
    {
        return array_filter($this->handlers, function($handler) use ($level) {
            return $handler->handlesLevel($level);
        });
    }

    private function shouldNotify(string $level): bool
    {
        return in_array($level, ['emergency', 'alert', 'critical']);
    }
}
