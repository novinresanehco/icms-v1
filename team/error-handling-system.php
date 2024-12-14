namespace App\Exceptions;

class Handler extends ExceptionHandler
{
    private AuditLogger $audit;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private SecurityManager $security;

    public function report(Throwable $e): void
    {
        $context = $this->getErrorContext($e);

        try {
            // Log error details
            $this->audit->logError($e, $context);
            
            // Record metrics
            $this->metrics->recordError($e, $context);
            
            // Check for critical conditions
            if ($this->isCriticalError($e)) {
                $this->handleCriticalError($e, $context);
            }
            
            parent::report($e);
            
        } catch (\Exception $reportingError) {
            // Ensure error reporting never fails silently
            $this->handleReportingFailure($e, $reportingError);
        }
    }

    public function render($request, Throwable $e): Response
    {
        try {
            $response = $this->generateErrorResponse($e, $request);
            
            // Add security headers
            return $this->security->secureResponse($response);
            
        } catch (\Exception $renderError) {
            // Fallback to basic error response
            return $this->fallbackResponse($e, $request);
        }
    }

    private function handleCriticalError(Throwable $e, array $context): void
    {
        // Alert system administrators
        $this->alerts->sendCriticalAlert($e, $context);
        
        // Execute emergency procedures if needed
        if ($this->requiresEmergencyProcedures($e)) {
            $this->executeEmergencyProcedures($e, $context);
        }
    }

    private function generateErrorResponse(Throwable $e, Request $request): Response
    {
        // Determine response type
        if ($request->expectsJson()) {
            return $this->generateJsonErrorResponse($e);
        }

        return $this->generateHtmlErrorResponse($e);
    }

    private function generateJsonErrorResponse(Throwable $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => $this->getErrorMessage($e),
                'code' => $this->getErrorCode($e),
                'type' => class_basename($e)
            ]
        ], $this->getHttpStatusCode($e));
    }

    private function getErrorMessage(Throwable $e): string
    {
        return app()->environment('production')
            ? $this->getProductionMessage($e)
            : $e->getMessage();
    }

    private function getProductionMessage(Throwable $e): string
    {
        return match(true) {
            $e instanceof ValidationException => 'Validation failed',
            $e instanceof AuthenticationException => 'Authentication failed',
            $e instanceof AuthorizationException => 'Unauthorized access',
            default => 'An error occurred'
        };
    }

    private function getHttpStatusCode(Throwable $e): int
    {
        return match(true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof NotFoundHttpException => 404,
            default => 500
        };
    }
}
