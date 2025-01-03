<?php

namespace App\Core\Error;

class ErrorHandler implements ErrorHandlerInterface
{
    private LogManager $logger;
    private MonitoringService $monitor;
    private AlertSystem $alerts;
    private SecurityService $security;

    public function __construct(
        LogManager $logger,
        MonitoringService $monitor,
        AlertSystem $alerts,
        SecurityService $security
    ) {
        $this->logger = $logger;
        $this->monitor = $monitor;
        $this->alerts = $alerts;
        $this->security = $security;
    }

    public function handleException(\Throwable $e, array $context = []): void
    {
        try {
            // Create error context
            $errorContext = $this->createErrorContext($e, $context);

            // Log the error
            $this->logger->critical($e->getMessage(), $errorContext);

            // Track monitoring metrics
            $this->monitor->trackError($errorContext);

            // Handle security implications
            $this->handleSecurityImplications($e, $errorContext);

            // Send alerts if needed
            $this->sendAlerts($e, $errorContext);

            // Execute recovery procedures
            $this->executeRecoveryProcedures($errorContext);

        } catch (\Exception $internalError) {
            // Fallback error handling
            $this->handleInternalError($internalError);
        }
    }

    protected function createErrorContext(\Throwable $e, array $additionalContext): array
    {
        return [
            'error' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_peak_usage(true),
            'additional' => $additionalContext
        ];
    }

    protected function handleSecurityImplications(\Throwable $e, array $context): void 
    {
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityEvent('security_error', [
                'error' => $context['error'],
                'security_context' => $context['additional']['security'] ?? []
            ]);
        }
    }

    protected function sendAlerts(\Throwable $e, array $context): void
    {
        $severity = $this->calculateSeverity($e, $context);

        if ($severity >= ErrorSeverity::CRITICAL) {
            $this->alerts->critical('Critical system error', [
                'error' => $context['error'],
                'severity' => $severity
            ]);
        } elseif ($severity >= ErrorSeverity::HIGH) {
            $this->alerts->high('High priority error', [
                'error' => $context['error'],
                'severity' => $severity
            ]);
        }
    }

    protected function executeRecoveryProcedures(array $context): void
    {
        try {
            // Execute relevant recovery procedures
            if ($this->requiresRecovery($context)) {
                $recoveryContext = new RecoveryContext($context);
                $this->executeRecovery($recoveryContext);
            }
        } catch (\Exception $e) {
            $this->logger->error('Recovery procedure failed', [
                'original_context' => $context,
                'recovery_error' => $e->getMessage()
            ]);
        }
    }

    private function calculateSeverity(\Throwable $e, array $context): int
    {
        if ($e instanceof CriticalException) {
            return ErrorSeverity::CRITICAL;
        }
        
        if ($e instanceof SecurityException) {
            return ErrorSeverity::HIGH;
        }

        return ErrorSeverity::NORMAL;
    }

    private function requiresRecovery(array $context): bool
    {
        return isset($context['error']['type']) && 
               in_array($context['error']['type'], [
                   CriticalException::class,
                   DataCorruptionException::class,
                   SystemFailureException::class
               ]);
    }

    private function executeRecovery(RecoveryContext $context): void
    {
        $this->monitor->startRecovery($context);
        
        try {
            DB::transaction(function() use ($context) {
                $recoverySteps = $this->determineRecoverySteps($context);
                
                foreach ($recoverySteps as $step) {
                    $step->execute($context);
                }
            });
            
        } finally {
            $this->monitor->endRecovery($context);
        }
    }

    private function handleInternalError(\Exception $e): void
    {
        try {
            error_log(sprintf(
                "Critical internal error in error handler: %s\nTrace: %s",
                $e->getMessage(),
                $e->getTraceAsString()
            ));
        } catch (\Exception $fatal) {
            // Last resort error logging
            error_log("FATAL: Error handler failure");
        }
    }
}

interface ErrorHandlerInterface 
{
    public function handleException(\Throwable $e, array $context = []): void;
}

class ErrorSeverity
{
    public const NORMAL = 1;
    public const HIGH = 2;
    public const CRITICAL = 3;
}

class RecoveryContext
{
    private array $context;
    private float $startTime;

    public function __construct(array $context)
    {
        $this->context = $context;
        $this->startTime = microtime(true);
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->startTime;
    }
}