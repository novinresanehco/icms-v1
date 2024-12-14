<?php

namespace App\Core\Exceptions;

use Illuminate\Support\Facades\{Log, DB};
use App\Core\Security\SecurityMonitor;

class ExceptionHandler
{
    protected SecurityMonitor $security;
    protected array $criticalExceptions = [
        'SecurityException',
        'AuthenticationException',
        'DataCorruptionException'
    ];

    public function __construct(SecurityMonitor $security)
    {
        $this->security = $security;
    }

    public function handle(\Throwable $exception): void
    {
        DB::beginTransaction();
        
        try {
            $this->logException($exception);
            
            if ($this->isCriticalException($exception)) {
                $this->handleCriticalException($exception);
            }
            
            if ($this->isSecurityException($exception)) {
                $this->handleSecurityException($exception);
            }

            $this->triggerAlerts($exception);
            
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::emergency('Exception handler failed', [
                'exception' => $e,
                'original_exception' => $exception
            ]);
        }
    }

    protected function logException(\Throwable $e): void
    {
        Log::error('Exception occurred', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious()?->getMessage(),
            'context' => [
                'user_id' => auth()->id(),
                'url' => request()->url(),
                'method' => request()->method(),
                'ip' => request()->ip()
            ]
        ]);
    }

    protected function handleCriticalException(\Throwable $e): void
    {
        $this->security->logCriticalEvent([
            'type' => 'critical_exception',
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        Cache::tags(['system'])->put('system_error', true, 300);
        
        $this->notifyAdministrators($e);
    }

    protected function handleSecurityException(\Throwable $e): void
    {
        $this->security->handleSecurityIncident([
            'type' => 'security_exception',
            'severity' => 'high',
            'details' => [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'context' => $this->getSecurityContext()
            ]
        ]);
    }

    protected function isCriticalException(\Throwable $e): bool
    {
        return in_array(get_class($e), $this->criticalExceptions) ||
            $e instanceof \Error ||
            $e->getCode() >= 500;
    }

    protected function isSecurityException(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
            $e instanceof AuthenticationException ||
            $e instanceof AuthorizationException;
    }

    protected function triggerAlerts(\Throwable $e): void
    {
        if ($this->isCriticalException($e)) {
            $this->security->triggerAlert([
                'level' => 'critical',
                'message' => 'Critical exception occurred',
                'exception' => get_class($e)
            ]);
        }
    }

    protected function getSecurityContext(): array
    {
        return [
            'request' => [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'method' => request()->method(),
                'url' => request()->url()
            ],
            'user' => auth()->user()?->only(['id', 'email']),
            'system' => [
                'memory' => memory_get_usage(true),
                'load' => sys_getloadavg()
            ]
        ];
    }
}
