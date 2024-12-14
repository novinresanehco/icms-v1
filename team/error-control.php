<?php

namespace App\Core\Error;

use Illuminate\Support\Facades\{Log, Cache, DB};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{ErrorHandlerInterface, MonitorInterface};

class ErrorControlSystem implements ErrorHandlerInterface
{
    private CoreSecurityManager $security;
    private MonitorInterface $monitor;
    private array $errorPatterns = [];
    private array $recoveryStrategies = [];

    public function handleException(\Throwable $e): void
    {
        DB::beginTransaction();
        
        try {
            $errorContext = $this->captureContext($e);
            $this->logError($e, $errorContext);
            
            if ($recoveryStrategy = $this->getRecoveryStrategy($e)) {
                $this->executeRecovery($recoveryStrategy, $errorContext);
            }
            
            $this->monitor->reportIncident($e, $errorContext);
            
            if ($this->isSystemCritical($e)) {
                $this->triggerEmergencyProtocol($e);
            }
            
            DB::commit();
        } catch (\Exception $innerException) {
            DB::rollBack();
            $this->handleCatastrophicFailure($e, $innerException);
        }
    }

    private function captureContext(\Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState(),
            'timestamp' => microtime(true)
        ];
    }

    private function logError(\Throwable $e, array $context): void
    {
        if ($e instanceof SecurityException) {
            Log::critical('Security exception occurred', $context);
            Cache::put('security_alert:' . time(), $context, 3600);
        } elseif ($e instanceof SystemException) {
            Log::emergency('System exception occurred', $context);
            Cache::put('system_alert:' . time(), $context, 3600);
        } else {
            Log::error('Application exception occurred', $context);
            Cache::put('error_log:' . time(), $context, 3600);
        }
    }

    private function executeRecovery(RecoveryStrategy $strategy, array $context): void
    {
        try {
            DB::beginTransaction();
            
            $strategy->execute($context);
            
            $this->monitor->recordRecovery([
                'strategy' => get_class($strategy),
                'context' => $context,
                'timestamp' => microtime(true)
            ]);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RecoveryFailedException(
                "Recovery strategy failed: " . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function triggerEmergencyProtocol(\Throwable $e): void
    {
        $protocol = new EmergencyProtocol();
        
        $protocol->execute([
            'exception' => $e,
            'system_state' => $this->captureSystemState(),
            'timestamp' => microtime(true)
        ]);
    }

    private function handleCatastrophicFailure(
        \Throwable $originalException,
        \Throwable $handlerException
    ): void {
        Log::emergency('Catastrophic failure in error handler', [
            'original_exception' => $originalException,
            'handler_exception' => $handlerException,
            'system_state' => $this->captureSystemState()
        ]);

        try {
            $this->notifyEmergencyContacts([
                'original_exception' => $originalException,
                'handler_exception' => $handlerException
            ]);
        } catch (\Exception $e) {
            // Last resort logging to system log
            error_log('CRITICAL: Error handler failure - ' . $e->getMessage());
        }
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_load' => sys_getloadavg(),
            'disk_space' => disk_free_space('/'),
            'active_connections' => $this->getActiveConnections(),
            'cache_stats' => $this->getCacheStats(),
            'query_stats' => $this->getQueryStats()
        ];
    }

    private function getActiveConnections(): array
    {
        return [
            'database' => DB::getConnections(),
            'cache' => Cache::getConnections()
        ];
    }

    private function getCacheStats(): array
    {
        return [
            'hits' => Cache::get('stats:hits', 0),
            'misses' => Cache::get('stats:misses', 0),
            'memory_usage' => Cache::get('stats:memory', 0)
        ];
    }

    private function getQueryStats(): array
    {
        return [
            'total_queries' => count(DB::getQueryLog()),
            'slow_queries' => $this->getSlowQueries(),
            'failed_queries' => Cache::get('stats:failed_queries', 0)
        ];
    }

    private function getSlowQueries(): array
    {
        return array_filter(DB::getQueryLog(), function($query) {
            return $query['time'] > 1000; // Queries taking more than 1 second
        });
    }

    private function isSystemCritical(\Throwable $e): bool
    {
        return $e instanceof SystemException ||
               $e instanceof SecurityException ||
               $e instanceof DatabaseException ||
               $this->isCriticalState();
    }

    private function isCriticalState(): bool
    {
        $state = $this->captureSystemState();
        
        return $state['memory_usage'] > 0.9 * $state['peak_memory'] ||
               $state['cpu_load'][0] > 0.8 ||
               $state['disk_space'] < 1024 * 1024 * 100; // Less than 100MB
    }
}
