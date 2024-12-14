<?php

namespace App\Exceptions;

use App\Core\Audit\AuditLogger;
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class CoreExceptionHandler extends ExceptionHandler
{
    private AuditLogger $audit;
    private SecurityManager $security;
    private SystemMonitor $monitor;

    private const CRITICAL_EXCEPTIONS = [
        SecurityException::class,
        SystemFailureException::class,
        DatabaseCorruptionException::class,
        CriticalResourceException::class
    ];

    public function __construct(
        AuditLogger $audit,
        SecurityManager $security,
        SystemMonitor $monitor
    ) {
        parent::__construct(app());
        $this->audit = $audit;
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function report(Throwable $e): void
    {
        try {
            // Capture system state
            $systemState = $this->monitor->captureSystemState();
            
            // Log exception with context
            $this->logException($e, $systemState);
            
            // Handle critical exceptions
            if ($this->isCriticalException($e)) {
                $this->handleCriticalException($e, $systemState);
            }
            
            parent::report($e);
            
        } catch (\Exception $reportingException) {
            // Fallback error logging
            $this->handleReportingFailure($reportingException, $e);
        }
    }

    public function render($request, Throwable $e)
    {
        try {
            // Validate security context
            $this->validateSecurityContext($request);
            
            // Sanitize exception details
            $sanitizedResponse = $this->prepareResponse($e);
            
            // Log response
            $this->audit->logExceptionResponse($e, $sanitizedResponse);
            
            return response()->json($sanitizedResponse, $this->getStatusCode($e));
            
        } catch (\Exception $renderException) {
            return $this->handleRenderFailure($renderException);
        }
    }

    private function logException(Throwable $e, array $systemState): void
    {
        $this->audit->logException([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $systemState,
            'timestamp' => now()
        ]);
    }

    private function handleCriticalException(Throwable $e, array $systemState): void
    {
        // Notify emergency contacts
        $this->notifyEmergencyContacts($e);
        
        // Trigger system alerts
        $this->monitor->triggerCriticalAlert($e);
        
        // Attempt system recovery
        $this->attemptSystemRecovery($e, $systemState);
    }

    private function validateSecurityContext($request): void
    {
        if (!$this->security->validateExceptionContext($request)) {
            throw new SecurityContextException('Invalid security context for exception handling');
        }
    }

    private function prepareResponse(Throwable $e): array
    {
        $response = [
            'status' => 'error',
            'message' => $this->sanitizeMessage($e->getMessage()),
            'code' => $this->getPublicErrorCode($e)
        ];

        if (config('app.debug')) {
            $response['debug'] = $this->getDebugInfo($e);
        }

        return $response;
    }

    private function sanitizeMessage(string $message): string
    {
        // Remove sensitive information
        $message = $this->removeSensitiveData($message);
        
        // Sanitize HTML/JavaScript
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $message;
    }

    private function getPublicErrorCode(Throwable $e): string
    {
        // Generate safe public error code
        return hash('sha256', get_class($e) . $e->getLine() . now()->toDateTimeString());
    }

    private function getDebugInfo(Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice($e->getTrace(), 0, 5) // Limit trace depth
        ];
    }

    private function getStatusCode(Throwable $e): int
    {
        return match (true) {
            $e instanceof SecurityException => 403,
            $e instanceof ValidationException => 422,
            $e instanceof NotFoundException => 404,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof RateLimitException => 429,
            default => 500
        };
    }

    private function isCriticalException(Throwable $e): bool
    {
        foreach (self::CRITICAL_EXCEPTIONS as $criticalType) {
            if ($e instanceof $criticalType) {
                return true;
            }
        }
        return false;
    }

    private function notifyEmergencyContacts(Throwable $e): void
    {
        try {
            $notificationService = app(EmergencyNotificationService::class);
            $notificationService->notifyCriticalException($e);
        } catch (\Exception $notificationException) {
            // Log notification failure but don't throw
            $this->audit->logNotificationFailure($notificationException);
        }
    }

    private function attemptSystemRecovery(Throwable $e, array $systemState): void
    {
        try {
            $recoveryService = app(SystemRecoveryService::class);
            $recoveryService->initiateRecovery($e, $systemState);
        } catch (\Exception $recoveryException) {
            // Log recovery failure but don't throw
            $this->audit->logRecoveryFailure($recoveryException);
        }
    }

    private function handleReportingFailure(\Exception $reportingException, Throwable $originalException): void
    {
        // Log to emergency fallback
        error_log(sprintf(
            "Critical failure in exception reporting: %s\nOriginal exception: %s",
            $reportingException->getMessage(),
            $originalException->getMessage()
        ));
    }

    private function handleRenderFailure(\Exception $e): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred',
            'code' => 'INTERNAL_SERVER_ERROR'
        ], 500);
    }

    private function removeSensitiveData(string $message): string
    {
        // Remove potential sensitive patterns (emails, tokens, etc.)
        $patterns = [
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[EMAIL]',
            '/[0-9]{16}/' => '[CARD_NUMBER]',
            '/bearer\s+[a-zA-Z0-9._-]+/' => '[TOKEN]',
            '/password\s*=\s*[^\s&]+/' => 'password=[HIDDEN]'
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message);
    }
}
