<?php

namespace App\Core\Error;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\MetricsCollector;
use Illuminate\Support\Facades\Log;

class CriticalErrorHandler implements ErrorHandlerInterface
{
    private MetricsCollector $metrics;
    private SecurityManager $security;
    private RecoveryService $recovery;
    
    public function __construct(
        MetricsCollector $metrics,
        SecurityManager $security,
        RecoveryService $recovery
    ) {
        $this->metrics = $metrics;
        $this->security = $security;
        $this->recovery = $recovery;
    }

    public function handleCriticalError(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult {
        DB::beginTransaction();
        
        try {
            // Record error metrics
            $this->recordErrorMetrics($e);
            
            // Create recovery point
            $recoveryId = $this->recovery->createRecoveryPoint();
            
            // Execute error handling
            $result = $this->executeErrorHandling($e, $operation, $context);
            
            // Log final status
            $this->logErrorResolution($e, $result);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $fatal) {
            DB::rollBack();
            return $this->handleFatalError($fatal, $e, $operation);
        }
    }

    protected function executeErrorHandling(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult {
        // Handle by error type
        $handler = $this->getErrorHandler($e);
        $result = $handler->handle($e, $operation, $context);
        
        // Apply security measures
        $this->security->handleErrorContext($context, $e);
        
        // Execute recovery if needed
        if ($result->requiresRecovery()) {
            $this->executeRecovery($result, $operation);
        }
        
        return $result;
    }

    protected function getErrorHandler(\Throwable $e): ErrorTypeHandler
    {
        return match(true) {
            $e instanceof SecurityException => new SecurityErrorHandler(),
            $e instanceof ValidationException => new ValidationErrorHandler(),
            $e instanceof BusinessException => new BusinessErrorHandler(),
            default => new GeneralErrorHandler()
        };
    }

    protected function executeRecovery(
        ErrorResult $result,
        Operation $operation
    ): void {
        try {
            $this->recovery->executeRecoveryPlan(
                $result->getRecoveryPlan(),
                $operation
            );
        } catch (\Exception $e) {
            Log::critical('Recovery failed', [
                'error' => $e->getMessage(),
                'operation' => get_class($operation)
            ]);
            throw $e;
        }
    }

    protected function recordErrorMetrics(\Throwable $e): void
    {
        $this->metrics->increment('error.count', [
            'type' => get_class($e),
            'code' => $e->getCode()
        ]);
        
        $this->metrics->record('error.context', [
            'memory' => memory_get_usage(true),
            'time' => microtime(true)
        ]);
    }

    protected function logErrorResolution(
        \Throwable $e,
        ErrorResult $result
    ): void {
        Log::error('Error handled', [
            'exception' => $e->getMessage(),
            'type' => get_class($e),
            'resolution' => $result->getResolutionType(),
            'recovery_needed' => $result->requiresRecovery(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleFatalError(
        \Throwable $fatal,
        \Throwable $original,
        Operation $operation
    ): ErrorResult {
        Log::critical('Fatal error during error handling', [
            'fatal' => $fatal->getMessage(),
            'original' => $original->getMessage(),
            'operation' => get_class($operation)
        ]);

        return new ErrorResult(
            ErrorResolutionType::FATAL,
            false,
            'Fatal error occurred during error handling'
        );
    }
}

interface ErrorTypeHandler
{
    public function handle(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult;
}

class SecurityErrorHandler implements ErrorTypeHandler
{
    public function handle(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult {
        // Handle security-specific errors
        return new ErrorResult(
            ErrorResolutionType::SECURITY,
            true,
            'Security error handled'
        );
    }
}

class ValidationErrorHandler implements ErrorTypeHandler
{
    public function handle(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult {
        // Handle validation-specific errors
        return new ErrorResult(
            ErrorResolutionType::VALIDATION,
            false,
            'Validation error handled'
        );
    }
}

class BusinessErrorHandler implements ErrorTypeHandler
{
    public function handle(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult {
        // Handle business logic errors
        return new ErrorResult(
            ErrorResolutionType::BUSINESS,
            false,
            'Business error handled'
        );
    }
}

class GeneralErrorHandler implements ErrorTypeHandler
{
    public function handle(
        \Throwable $e,
        Operation $operation,
        SecurityContext $context
    ): ErrorResult {
        // Handle general system errors
        return new ErrorResult(
            ErrorResolutionType::GENERAL,
            true,
            'General error handled'
        );
    }
}

enum ErrorResolutionType
{
    case SECURITY;
    case VALIDATION;
    case BUSINESS;
    case GENERAL;
    case FATAL;
}

class ErrorResult
{
    public function __construct(
        private ErrorResolutionType $type,
        private bool $requiresRecovery,
        private string $message
    ) {}

    public function getResolutionType(): ErrorResolutionType
    {
        return $this->type;
    }

    public function requiresRecovery(): bool
    {
        return $this->requiresRecovery;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getRecoveryPlan(): ?RecoveryPlan
    {
        if (!$this->requiresRecovery) {
            return null;
        }
        
        return new RecoveryPlan();
    }
}
