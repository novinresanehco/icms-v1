<?php

namespace App\Core\Error;

use App\Core\Monitoring\MonitoringService;
use App\Core\Logging\LogManager;
use App\Exceptions\SystemException;

class ErrorHandler implements ErrorHandlerInterface
{
    private MonitoringService $monitor;
    private LogManager $logger;
    private array $config;
    private array $handlers = [];
    private array $errors = [];

    public function __construct(
        MonitoringService $monitor,
        LogManager $logger,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function handleError(\Throwable $error, array $context = []): void
    {
        $errorId = $this->generateErrorId();
        
        try {
            // Record error
            $this->recordError($errorId, $error, $context);
            
            // Execute type-specific handler
            $this->executeHandler($error, $context);
            
            // Trigger monitoring alert
            $this->triggerErrorAlert($error, $errorId);
            
            // Log error details
            $this->logErrorDetails($errorId, $error, $context);
            
        } catch (\Throwable $e) {
            // Emergency handling if error handling fails
            $this->handleCriticalFailure($e, $error);
        }
    }

    public function registerErrorHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function getErrorReport(string $errorId): ErrorReport
    {
        if (!isset($this->errors[$errorId])) {
            throw new SystemException("Error ID not found: $errorId");
        }

        return new ErrorReport($this->errors[$errorId]);
    }

    public function analyzeErrorPatterns(): ErrorAnalysis
    {
        return new ErrorAnalysis([
            'patterns' => $this->detectErrorPatterns(),
            'frequencies' => $this->calculateErrorFrequencies(),
            'correlations' => $this->findErrorCorrelations(),
            'recommendations' => $this->generateRecommendations()
        ]);
    }

    private function recordError(string $errorId, \Throwable $error, array $context): void
    {
        $this->errors[$errorId] = [
            'id' => $errorId,
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'context' => $context,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'system_state' => $this->captureSystemState()
        ];
    }

    private function executeHandler(\Throwable $error, array $context): void
    {
        $type = get_class($error);
        
        if (isset($this->handlers[$type])) {
            try {
                $this->handlers[$type]($error, $context);
            } catch (\Throwable $e) {
                $this->logger->critical('Error handler failed', [
                    'handler_type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function triggerErrorAlert(\Throwable $error, string $errorId): void
    {
        $severity = $this->determineErrorSeverity($error);
        
        $this->monitor->triggerAlert('error_occurred', [
            'error_id' => $errorId,
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'severity' => $severity
        ], $severity);
    }

    private function logErrorDetails(string $errorId, \Throwable $error, array $context): void
    {
        $this->logger->error('Error occurred', [
            'error_id' => $errorId,
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'context' => $context,
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function handleCriticalFailure(\Throwable $handlingError, \Throwable $originalError): void
    {
        // Emergency logging to system log
        error_log(sprintf(
            "CRITICAL: Error handler failed\nOriginal error: %s\nHandling error: %s",
            $originalError->getMessage(),
            $handlingError->getMessage()
        ));

        // Try to notify monitoring system
        try {
            $this->monitor->triggerAlert('error_handler_failed', [
                'handling_error' => $handlingError->getMessage(),
                'original_error' => $originalError->getMessage()
            ], 'critical');
        } catch (\Throwable $e) {
            // Last resort: system log
            error_log('Failed to notify monitoring system: ' . $e->getMessage());
        }
    }

    private function generateErrorId(): string
    {
        return uniqid('error_', true);
    }

    private function determineErrorSeverity(\Throwable $error): string
    {
        foreach ($this->config['severity_mapping'] as $type => $severity) {
            if ($error instanceof $type) {
                return $severity;
            }
        }
        return 'error';
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg(),
            'disk_space' => disk_free_space('/'),
            'php_version' => PHP_VERSION,
            'load_extensions' => get_loaded_extensions(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
        ];
    }
}
