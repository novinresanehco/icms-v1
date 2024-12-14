<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Notification\NotificationManager;
use Throwable;

class Handler extends ExceptionHandler
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private NotificationManager $notifier;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        NotificationManager $notifier
    ) {
        parent::__construct($app);
        
        $this->security = $security;
        $this->monitor = $monitor;
        $this->notifier = $notifier;
    }

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->handleCriticalException($e);
        });

        $this->renderable(function (Throwable $e, $request) {
            return $this->renderSecureResponse($e, $request);
        });
    }

    private function handleCriticalException(Throwable $e): void
    {
        $monitoringId = $this->monitor->startOperation('exception_handling');
        
        try {
            // Record exception details
            $this->monitor->recordException($e);
            
            // Handle security implications
            if ($this->isSecurityException($e)) {
                $this->handleSecurityException($e);
            }
            
            // Notify relevant parties
            $this->notifyCriticalException($e);
            
            // Log with full context
            $this->logExceptionWithContext($e);
            
        } catch (Throwable $inner) {
            // Failsafe logging
            $this->performFailsafeLogging($e, $inner);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function handleSecurityException(Throwable $e): void
    {
        $this->security->handleSecurityBreach([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $this->security->getCurrentContext()
        ]);

        $this->notifier->sendSecurityAlert(
            'security_exception',
            [
                'type' => get_class($e),
                'message' => $e->getMessage()
            ]
        );
    }

    private function renderSecureResponse(Throwable $e, $request): Response
    {
        if ($this->isSecurityException($e)) {
            return $this->renderSecurityException($e, $request);
        }

        if ($request->expectsJson()) {
            return $this->renderJsonException($e);
        }

        return $this->renderHttpException($e);
    }

    private function renderSecurityException(Throwable $e, $request): Response
    {
        $statusCode = $this->getSecurityStatusCode($e);
        
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'message' => 'Security violation detected',
                    'code' => $statusCode
                ]
            ], $statusCode);
        }

        return response()->view(
            'errors.security',
            ['exception' => $e],
            $statusCode
        );
    }

    private function renderJsonException(Throwable $e): Response
    {
        return response()->json([
            'error' => [
                'message' => $this->getSanitizedMessage($e),
                'code' => $this->getStatusCode($e)
            ]
        ], $this->getStatusCode($e));
    }

    private function getSecurityStatusCode(Throwable $e): int
    {
        return match (get_class($e)) {
            UnauthorizedException::class => 401,
            ForbiddenException::class => 403,
            SecurityValidationException::class => 400,
            SecurityBreachException::class => 403,
            default => 500
        };
    }

    private function getSanitizedMessage(Throwable $e): string
    {
        return app()->environment('production')
            ? 'An error occurred processing your request.'
            : $e->getMessage();
    }

    private function logExceptionWithContext(Throwable $e): void
    {
        $context = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() ? [
                'type' => get_class($e->getPrevious()),
                'message' => $e->getPrevious()->getMessage()
            ] : null,
            'system_state' => $this->monitor->captureSystemState(),
            'security_context' => $this->security->getCurrentContext()
        ];

        logger()->error('Critical exception occurred', $context);
    }

    private function performFailsafeLogging(Throwable $primary, Throwable $secondary): void
    {
        error_log(sprintf(
            "Critical exception handler failure\nPrimary: %s\nSecondary: %s",
            $primary->getMessage(),
            $secondary->getMessage()
        ));
    }
}
