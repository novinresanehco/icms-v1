<?php

namespace App\Core\Error;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Logging\LogManager;
use Throwable;

class ErrorManager implements ErrorInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private LogManager $logger;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        LogManager $logger,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function handleError(Throwable $error): void
    {
        $monitoringId = $this->monitor->startOperation('error_handling');
        
        try {
            $secureError = $this->sanitizeError($error);
            
            $this->logError($secureError);
            $this->notifyError($secureError);
            
            if ($this->isCriticalError($secureError)) {
                $this->handleCriticalError($secureError);
            }
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (Throwable $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleFatalError($e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function reportError(string $type, string $message, array $context = []): void
    {
        $monitoringId = $this->monitor->startOperation('error_reporting');
        
        try {
            $this->validateErrorReport($type, $message, $context);
            
            $error = $this->createError($type, $message, $context);
            $this->processError($error);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (Throwable $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleFatalError($e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function sanitizeError(Throwable $error): array
    {
        return [
            'type' => get_class($error),
            'message' => $this->sanitizeMessage($error->getMessage()),
            'code' => $error->getCode(),
            'file' => $this->sanitizePath($error->getFile()),
            'line' => $error->getLine(),
            'trace' => $this->sanitizeTrace($error->getTraceAsString()),
            'context' => $this->collectContext()
        ];
    }

    private function logError(array $error): void
    {
        $level = $this->determineLogLevel($error);
        
        $this->logger->log($level, $error['message'], [
            'error' => $error,
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    private function notifyError(array $error): void
    {
        if ($this->shouldNotify($error)) {
            $this->sendNotifications($error);
        }
    }

    private function handleCriticalError(array $error): void
    {
        $this->security->handleSecurityEvent('critical_error', $error);
        $this->monitor->recordCriticalEvent($error);
        
        if ($this->config['shutdown_on_critical']) {
            $this->initiateGracefulShutdown();
        }
    }

    private function handleFatalError(Throwable $error): void
    {
        try {
            $this->logger->emergency('Fatal error in error handler', [
                'error' => $this->sanitizeError($error)
            ]);
        } finally {
            $this->initiateEmergencyShutdown();
        }
    }

    private function validateErrorReport(string $type, string $message, array $context): void
    {
        if (empty($type)) {
            throw new ErrorException('Error type cannot be empty');
        }

        if (empty($message)) {
            throw new ErrorException('Error message cannot be empty');
        }

        if (!$this->isValidErrorType($type)) {
            throw new ErrorException('Invalid error type');
        }

        if (!$this->validateContext($context)) {
            throw new ErrorException('Invalid error context');
        }
    }

    private function createError(string $type, string $message, array $context): array
    {
        return [
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => now(),
            'environment' => app()->environment(),
            'request_id' => request()->id(),
            'user_id' => auth()->id()
        