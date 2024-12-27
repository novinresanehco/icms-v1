<?php

namespace App\Core\Error;

class ErrorHandler
{
    private LoggerInterface $logger;
    private AlertManager $alerts;
    private MetricsCollector $metrics;

    public function handle(\Throwable $e): void
    {
        $this->logError($e);
        $this->metrics->increment('error_count');

        if ($this->isCritical($e)) {
            $this->handleCriticalError($e);
        }
    }

    private function logError(\Throwable $e): void
    {
        $this->logger->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function isCritical(\Throwable $e): bool
    {
        return $e instanceof SecurityException 
            || $e instanceof DatabaseException
            || $e instanceof SystemException;
    }

    private function handleCriticalError(\Throwable $e): void
    {
        $this->alerts->critical('Critical system error', [
            'message' => $e->getMessage(),
            'type' => get_class($e)
        ]);
    }
}

class ExceptionHandler
{
    private ErrorHandler $errorHandler;
    private JsonResponder $responder;

    public function render($request, \Throwable $e)
    {
        $this->errorHandler->handle($e);

        if ($request->expectsJson()) {
            return $this->renderJson($e);
        }

        return $this->renderHtml($e);
    }

    private function renderJson(\Throwable $e)
    {
        $status = $this->getStatusCode($e);
        
        return $this->responder->error(
            $this->getMessage($e),
            $status
        );
    }

    private function renderHtml(\Throwable $e)
    {
        if ($this->shouldShowDetails($e)) {
            return view('errors.detailed', ['error' => $e]);
        }

        return view('errors.generic');
    }

    private function getMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return $e->getMessage();
        }

        if (app()->environment('production')) {
            return 'An error occurred';
        }

        return $e->getMessage();
    }

    private function getStatusCode(\Throwable $e): int
    {
        return match(true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof NotFoundException => 404,
            default => 500
        };
    }

    private function shouldShowDetails(\Throwable $e): bool
    {
        return !app()->environment('production')
            || $e instanceof ValidationException;
    }
}

class RecoveryManager
{
    private BackupService $backup;
    private AlertManager $alerts;
    private MetricsCollector $metrics;

    public function recover(\Throwable $e): void
    {
        $this->metrics->increment('recovery_attempts');
        
        try {
            if ($this->canRecover($e)) {
                $this->performRecovery($e);
                $this->metrics->increment('recovery_success');
            }
        } catch (\Exception $recoveryError) {
            $this->handleRecoveryFailure($e, $recoveryError);
        }
    }

    private function canRecover(\Throwable $e): bool
    {
        return $e instanceof RecoverableException;
    }

    private function performRecovery(\Throwable $e): void
    {
        match(true) {
            $e instanceof DatabaseException => $this->recoverDatabase(),
            $e instanceof CacheException => $this->recoverCache(),
            $e instanceof FileSystemException => $this->recoverFileSystem(),
            default => throw new \RuntimeException('Unknown recovery type')
        };
    }

    private function handleRecoveryFailure(
        \Throwable $original,
        \Exception $recovery
    ): void {
        $this->metrics->increment('recovery_failures');
        
        $this->alerts->emergency('Recovery failed', [
            'original_error' => $original->getMessage(),
            'recovery_error' => $recovery->getMessage()
        ]);
    }
}
