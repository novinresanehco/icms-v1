<?php

namespace App\Core\Exceptions;

class CriticalExceptionHandler
{
    private $logger;
    private $monitor;
    private $alerts;

    public function handle(\Throwable $e): Response
    {
        $this->monitor->trackException($e);

        try {
            // Handle by type
            $response = match(true) {
                $e instanceof SecurityException => $this->handleSecurityException($e),
                $e instanceof ValidationException => $this->handleValidationException($e),
                $e instanceof DatabaseException => $this->handleDatabaseException($e),
                default => $this->handleUnknownException($e)
            };

            // Log complete details
            $this->logException($e);

            return $response;

        } catch (\Throwable $inner) {
            return $this->handleCriticalFailure($e, $inner);
        }
    }

    private function handleSecurityException(SecurityException $e): Response
    {
        $this->alerts->securityAlert($e);
        return new Response(['error' => 'Security Error'], 403);
    }

    private function handleCriticalFailure(\Throwable $e, \Throwable $inner): Response
    {
        $this->alerts->criticalAlert($e, $inner);
        return new Response(['error' => 'Critical System Error'], 500);
    }

    private function logException(\Throwable $e): void
    {
        $this->logger->critical('Exception occurred', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'time' => time(),
            'severity' => $this->determineSeverity($e)
        ]);
    }
}
