<?php

namespace App\Core\Error;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\{AuditLogger, NotificationService};
use Illuminate\Support\Facades\{DB, Log};
use App\Core\Exceptions\{SystemException, SecurityException};

class ErrorManager implements ErrorManagerInterface
{
    private CoreSecurityManager $security;
    private AuditLogger $auditLogger;
    private NotificationService $notifier;
    private array $config;

    private const CRITICAL_ERRORS = [
        'security_breach',
        'data_corruption',
        'system_failure',
        'service_unavailable'
    ];

    public function __construct(
        CoreSecurityManager $security,
        AuditLogger $auditLogger,
        NotificationService $notifier,
        array $config
    ) {
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->notifier = $notifier;
        $this->config = $config;
    }

    public function handleException(\Throwable $e, array $context = []): void
    {
        $this->security->executeSecureOperation(
            function() use ($e, $context) {
                $errorData = $this->collectErrorData($e, $context);
                
                DB::transaction(function() use ($errorData) {
                    $this->logError($errorData);
                    $this->processError($errorData);
                    
                    if ($this->isCriticalError($errorData)) {
                        $this->handleCriticalError($errorData);
                    }
                });
            },
            ['action' => 'handle_error', 'type' => get_class($e)]
        );
    }

    public function registerErrorHandler(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    public function handleError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): void {
        $this->handleException(
            new \ErrorException($message, 0, $severity, $file, $line)
        );
    }

    public function handleFatalError(): void
    {
        $error = error_get_last();
        if ($error && $this->isFatalError($error['type'])) {
            $this->handleException(
                new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                )
            );
        }
    }

    protected function collectErrorData(\Throwable $e, array $context): array
    {
        return [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() ? $this->collectErrorData($e->getPrevious(), []) : null,
            'context' => $this->sanitizeContext($context),
            'server' => $this->getServerData(),
            'timestamp' => microtime(true),
            'environment' => config('app.env'),
            'session_id' => session()->getId(),
            'user_id' => auth()->id(),
            'request' => $this->getRequestData()
        ];
    }

    protected function logError(array $errorData): void
    {
        $level = $this->getErrorLevel($errorData);
        Log::log($level, $errorData['message'], $errorData);
        
        $this->auditLogger->logError([
            'error' => $errorData,
            'level' => $level,
            'timestamp' => now()
        ]);
    }

    protected function processError(array $errorData): void
    {
        $this->updateErrorStats($errorData);
        $this->checkErrorThresholds($errorData);
        $this->triggerErrorHooks($errorData);
    }

    protected function handleCriticalError(array $errorData): void
    {
        $this->notifyEmergencyContacts($errorData);
        $this->createIncidentReport($errorData);
        $this->initiateEmergencyProtocols($errorData);
        $this->captureSystemState($errorData);
    }

    protected function sanitizeContext(array $context): array
    {
        return array_map(
            fn($value) => $this->sanitizeValue($value),
            array_filter(
                $context,
                fn($key) => !in_array($key, $this->config['excluded_context']),
                ARRAY_FILTER_USE_KEY
            )
        );
    }

    protected function sanitizeValue($value)
    {
        if (is_string($value)) {
            return $this->sanitizeString($value);
        }
        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }
        return $value;
    }

    protected function sanitizeString(string $value): string
    {
        return preg_replace(
            $this->config['sanitize_patterns'],
            '',
            strip_tags($value)
        );
    }

    protected function sanitizeArray(array $value): array
    {
        return array_map([$this, 'sanitizeValue'], $value);
    }

    protected function getServerData(): array
    {
        return [
            'hostname' => gethostname(),
            'load' => sys_getloadavg(),
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }

    protected function getRequestData(): array
    {
        if (!app()->runningInConsole()) {
            return [
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ];
        }
        return ['console' => true];
    }

    protected function isCriticalError(array $errorData): bool
    {
        return in_array($errorData['type'], self::CRITICAL_ERRORS) ||
            $this->matchesCriticalPatterns($errorData);
    }

    protected function matchesCriticalPatterns(array $errorData): bool
    {
        foreach ($this->config['critical_patterns'] as $pattern) {
            if (preg_match($pattern, $errorData['message'])) {
                return true;
            }
        }
        return false;
    }

    protected function getErrorLevel(array $errorData): string
    {
        if ($this->isCriticalError($errorData)) {
            return 'critical';
        }
        if ($errorData['type'] === SecurityException::class) {
            return 'error';
        }
        return 'warning';
    }

    protected function isFatalError(int $type): bool
    {
        return in_array($type, [
            E_ERROR,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_PARSE
        ]);
    }
}
