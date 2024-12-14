<?php

namespace App\Core\Error;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Security\SecurityManager;

class ErrorHandlingSystem
{
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private NotificationService $notifications;
    private MetricsCollector $metrics;

    public function handleCriticalError(\Throwable $e, array $context): void
    {
        DB::beginTransaction();
        
        try {
            $errorId = $this->logError($e, $context);
            $this->notifyRelevantParties($errorId, $e);
            $this->executeRecoveryProcedures($e, $context);
            $this->updateMetrics($e);
            
            DB::commit();
            
        } catch (\Throwable $secondary) {
            DB::rollBack();
            $this->handleCatastrophicFailure($e, $secondary);
        }
    }

    public function executeWithErrorHandling(callable $operation, array $context): mixed
    {
        try {
            return $this->security->executeCriticalOperation(
                fn() => $operation(),
                $context
            );
            
        } catch (\Throwable $e) {
            $this->handleCriticalError($e, $context);
            throw $this->sanitizeException($e);
        }
    }

    private function logError(\Throwable $e, array $context): string
    {
        $errorId = $this->generateErrorId();
        
        $this->auditLogger->logError([
            'error_id' => $errorId,
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => $this->sanitizeContext($context),
            'timestamp' => now(),
            'system_state' => $this->captureSystemState()
        ]);

        return $errorId;
    }

    private function notifyRelevantParties(string $errorId, \Throwable $e): void
    {
        if ($this->isCriticalError($e)) {
            $this->notifications->notifyEmergencyTeam($errorId, $e);
        }

        if ($this->isSecurityError($e)) {
            $this->notifications->notifySecurityTeam($errorId, $e);
        }

        $this->notifications->notifySystemAdministrators($errorId, $e);
    }

    private function executeRecoveryProcedures(\Throwable $e, array $context): void
    {
        if ($this->isRecoverable($e)) {
            $this->attemptRecovery($e, $context);
        } else {
            $this->executeEmergencyProtocols($e, $context);
        }
    }

    private function handleCatastrophicFailure(
        \Throwable $primary,
        \Throwable $secondary
    ): void {
        try {
            Log::emergency('Catastrophic system failure', [
                'primary_error' => [
                    'type' => get_class($primary),
                    'message' => $primary->getMessage(),
                    'trace' => $primary->getTraceAsString()
                ],
                'secondary_error' => [
                    'type' => get_class($secondary),
                    'message' => $secondary->getMessage(),
                    'trace' => $secondary->getTraceAsString()
                ],
                'system_state' => $this->captureSystemState()
            ]);

            $this->notifications->notifyCatastrophicFailure($primary, $secondary);
            
        } catch (\Throwable $e) {
            // Last resort logging to system error log
            error_log(sprintf(
                'CATASTROPHIC: Primary[%s] Secondary[%s] Final[%s]',
                $primary->getMessage(),
                $secondary->getMessage(),
                $e->getMessage()
            ));
        }
    }

    private function sanitizeException(\Throwable $e): \Exception
    {
        if ($this->shouldExposeTechnicalDetails()) {
            return $e;
        }

        return new \Exception(
            'An internal system error occurred. Please contact support.',
            $e->getCode()
        );
    }

    private function sanitizeContext(array $context): array
    {
        return array_filter($context, function($key) {
            return !in_array($key, ['password', 'token', 'secret']);
        }, ARRAY_FILTER_USE_KEY);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_load' => sys_getloadavg()[0],
            'db_connections' => DB::getConnections(),
            'cache_stats' => Cache::getStats(),
            'timestamp' => microtime(true)
        ];
    }

    private function generateErrorId(): string
    {
        return sprintf(
            '%s-%s',
            date('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }

    private function isCriticalError(\Throwable $e): bool
    {
        return $e instanceof CriticalException ||
               $e instanceof \PDOException ||
               $e->getCode() >= 500;
    }

    private function isSecurityError(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof AuthenticationException ||
               $e instanceof AuthorizationException;
    }

    private function isRecoverable(\Throwable $e): bool
    {
        return !($e instanceof CatastrophicException) &&
               !($e instanceof CorruptionException) &&
               $e->getCode() < 500;
    }

    private function shouldExposeTechnicalDetails(): bool
    {
        return config('app.debug') &&
               config('app.env') !== 'production';
    }
}
