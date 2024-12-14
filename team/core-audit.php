<?php

namespace App\Core\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class AuditLogger
{
    private Logger $logger;
    private string $logPath;

    public function __construct()
    {
        $this->logPath = storage_path('logs/audit.log');
        $this->logger = new Logger('audit');
        $this->logger->pushHandler(new StreamHandler($this->logPath, Logger::INFO));
    }

    public function logSuccess(string $operation, array $context, $result): void
    {
        $this->log('success', $operation, [
            'context' => $context,
            'result' => $this->sanitizeResult($result),
            'execution_time' => $this->getExecutionTime(),
            'memory_usage' => memory_get_peak_usage(true)
        ]);
    }

    public function logFailure(string $operation, \Throwable $e, array $context): void
    {
        $this->log('failure', $operation, [
            'context' => $context,
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'execution_time' => $this->getExecutionTime(),
            'memory_usage' => memory_get_peak_usage(true)
        ]);
    }

    public function logSecurityEvent(string $event, array $context): void
    {
        $this->log('security', $event, [
            'context' => $context,
            'timestamp' => now(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    private function log(string $level, string $message, array $context): void
    {
        $data = array_merge($context, [
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip()
        ]);

        $this->logger->log($level, $message, $data);
    }

    private function sanitizeResult($result): array
    {
        // Remove sensitive data before logging
        if (is_object($result)) {
            return [
                'type' => get_class($result),
                'id' => $result->id ?? null
            ];
        }

        if (is_array($result)) {
            return array_map([$this, 'sanitizeResult'], $result);
        }

        return ['value' => $result];
    }

    private function getExecutionTime(): float
    {
        return microtime(true) - LARAVEL_START;
    }
}
