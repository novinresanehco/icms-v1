<?php

namespace App\Core\Error;

use Illuminate\Support\Facades\{Log, DB, Cache, Mail};
use App\Core\Services\{SecurityService, NotificationService};
use App\Core\Models\{SystemError, ErrorLog, RecoveryPoint};
use App\Core\Exceptions\{SystemException, RecoveryException};

class ErrorHandler
{
    private SecurityService $security;
    private NotificationService $notifier;
    private array $config;
    private array $recoveryPoints = [];

    private const MAX_RETRIES = 3;
    private const BACKOFF_MS = 100;
    private const CRITICAL_THRESHOLD = 5;

    public function __construct(
        SecurityService $security,
        NotificationService $notifier,
        array $config
    ) {
        $this->security = $security;
        $this->notifier = $notifier;
        $this->config = $config;
    }

    public function handleException(\Throwable $e, array $context = []): void
    {
        try {
            DB::beginTransaction();

            // Record error
            $error = $this->recordError($e, $context);
            
            // Analyze severity
            $severity = $this->analyzeSeverity($e, $context);
            
            // Execute recovery if needed
            if ($this->needsRecovery($severity)) {
                $this->executeRecovery($error);
            }
            
            // Notify relevant parties
            $this->handleNotifications($error, $severity);
            
            // Update system state
            $this->updateSystemState($error);

            DB::commit();

        } catch (\Exception $handleError) {
            DB::rollBack();
            $this->handleCriticalFailure($e, $handleError);
        }
    }

    public function createRecoveryPoint(string $identifier): string
    {
        try {
            $point = RecoveryPoint::create([
                'identifier' => $identifier,
                'state' => $this->captureSystemState(),
                'created_at' => now()
            ]);

            $this->recoveryPoints[$identifier] = $point->id;
            return $point->id;

        } catch (\Exception $e) {
            throw new RecoveryException('Failed to create recovery point: ' . $e->getMessage());
        }
    }

    public function recover(string $pointId): bool
    {
        try {
            DB::beginTransaction();

            $point = RecoveryPoint::findOrFail($pointId);
            $this->validateRecoveryPoint($point);
            
            // Execute recovery steps
            $this->restoreSystemState($point->state);
            $this->verifyRecovery($point);
            
            DB::commit();
            
            $this->logRecovery($point);
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new RecoveryException('Recovery failed: ' . $e->getMessage());
        }
    }

    protected function recordError(\Throwable $e, array $context): SystemError
    {
        return SystemError::create([
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'context' => $this->sanitizeContext($context),
            'created_at' => now()
        ]);
    }

    protected function analyzeSeverity(\Throwable $e, array $context): string
    {
        $severity = 'low';

        // Check error type
        if ($e instanceof SecurityException) {
            $severity = 'critical';
        } elseif ($e instanceof SystemException) {
            $severity = 'high';
        }

        // Check error frequency
        $count = SystemError::where('type', get_class($e))
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count >= self::CRITICAL_THRESHOLD) {
            $severity = 'critical';
        }

        // Check impact
        if (isset($context['users_affected']) && $context['users_affected'] > 100) {
            $severity = 'critical';
        }

        return $severity;
    }

    protected function needsRecovery(string $severity): bool
    {
        return in_array($severity, ['high', 'critical']);
    }

    protected function executeRecovery(SystemError $error): void
    {
        $attempts = 0;
        
        while ($attempts < self::MAX_RETRIES) {
            try {
                // Find latest valid recovery point
                $point = $this->findLatestRecoveryPoint();
                
                if ($point) {
                    // Execute recovery
                    $this->recover($point->id);
                    return;
                }

                usleep(self::BACKOFF_MS * (2 ** $attempts));
                $attempts++;

            } catch (\Exception $e) {
                Log::error('Recovery attempt failed', [
                    'attempt' => $attempts + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }

        throw new RecoveryException('Recovery failed after ' . self::MAX_RETRIES . ' attempts');
    }

    protected function handleNotifications(SystemError $error, string $severity): void
    {
        // Notify system administrators
        if ($severity === 'critical') {
            $this->notifier->sendCriticalAlert([
                'error' => $error,
                'severity' => $severity,
                'timestamp' => now()
            ]);
        }

        // Log to monitoring system
        $this->notifier->logSystemEvent([
            'type' => 'error',
            'severity' => $severity,
            'details' => [
                'error_id' => $error->id,
                'message' => $error->message
            ]
        ]);
    }

    protected function updateSystemState(SystemError $error): void
    {
        Cache::tags(['system_state'])->put(
            'last_error',
            [
                'id' => $error->id,
                'type' => $error->type,
                'timestamp' => $error->created_at
            ],
            3600
        );
    }

    protected function handleCriticalFailure(\Throwable $original, \Exception $handler): void
    {
        Log::critical('Critical error handler failure', [
            'original_error' => [
                'message' => $original->getMessage(),
                'trace' => $original->getTraceAsString()
            ],
            'handler_error' => [
                'message' => $handler->getMessage(),
                'trace' => $handler->getTraceAsString()
            ]
        ]);

        // Force system into safe mode if configured
        if ($this->config['safe_mode_on_critical']) {
            $this->enterSafeMode();
        }

        // Send emergency notification
        $this->notifier->sendEmergencyAlert([
            'original_error' => $original->getMessage(),
            'handler_error' => $handler->getMessage(),
            'timestamp' => now()
        ]);
    }

    protected function sanitizeContext(array $context): array
    {
        return array_map(function ($value) {
            if (is_object($value)) {
                return get_class($value);
            }
            return $value;
        }, $context);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'system_load' => sys_getloadavg(),
            'cache_state' => $this->getImportantCacheKeys(),
            'timestamp' => now()
        ];
    }

    protected function enterSafeMode(): void
    {
        Cache::tags(['system_state'])->put('safe_mode', true, 3600);
        $this->notifier->broadcastSystemStatus('safe_mode');
    }
}
