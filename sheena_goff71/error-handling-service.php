namespace App\Core\Error;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Log, Event};
use Throwable;

class ErrorHandlingService implements ErrorHandlingInterface
{
    private SecurityManager $security;
    private ExceptionManager $exceptionManager;
    private ErrorLogger $logger;
    private AlertManager $alerts;
    private ErrorConfig $config;
    private SecurityContext $context;

    public function __construct(
        SecurityManager $security,
        ExceptionManager $exceptionManager,
        ErrorLogger $logger,
        AlertManager $alerts,
        ErrorConfig $config
    ) {
        $this->security = $security;
        $this->exceptionManager = $exceptionManager;
        $this->logger = $logger;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function handleException(Throwable $exception, array $context = []): ErrorResponse
    {
        try {
            $securityContext = $this->createSecurityContext($context);
            
            return $this->security->executeCriticalOperation(
                new HandleExceptionOperation($exception),
                $securityContext,
                function() use ($exception, $context) {
                    // Classify and handle the exception
                    $classification = $this->exceptionManager->classify($exception);
                    $sanitizedContext = $this->sanitizeContext($context);
                    
                    // Log the exception with secure context
                    $this->logException($exception, $classification, $sanitizedContext);
                    
                    // Handle critical errors
                    if ($classification->isCritical()) {
                        $this->handleCriticalError($exception, $sanitizedContext);
                    }
                    
                    // Generate appropriate response
                    $response = $this->generateResponse($exception, $classification);
                    
                    // Trigger notifications if needed
                    $this->triggerNotifications($exception, $classification);
                    
                    return $response;
                }
            );
        } catch (Throwable $e) {
            // Failsafe error handling
            return $this->handleFailsafeError($e, $exception);
        }
    }

    private function handleCriticalError(Throwable $exception, array $context): void
    {
        // Log critical error
        $this->logger->logCritical($exception, $context);
        
        // Send immediate alerts
        $this->alerts->sendCriticalAlert($exception);
        
        // Execute emergency procedures
        $this->executeEmergencyProcedures($exception);
        
        // Trigger system events
        Event::dispatch(new CriticalErrorEvent($exception));
    }

    private function executeEmergencyProcedures(Throwable $exception): void
    {
        try {
            // Execute failsafe procedures
            $this->exceptionManager->executeFailsafe($exception);
            
            // Verify system stability
            $this->verifySystemState();
            
            // Initiate recovery if needed
            if ($this->requiresRecovery($exception)) {
                $this->initiateRecovery($exception);
            }
        } catch (Throwable $e) {
            // Last resort error handling
            $this->handleFailsafeError($e, $exception);
        }
    }

    private function generateResponse(Throwable $exception, ErrorClassification $classification): ErrorResponse
    {
        $responseData = [
            'status' => 'error',
            'code' => $this->determineErrorCode($exception),
            'message' => $this->sanitizeErrorMessage($exception->getMessage()),
            'reference' => $this->generateErrorReference($exception)
        ];

        if ($this->config->isDebugMode() && !$classification->isSensitive()) {
            $responseData['debug'] = $this->generateDebugData($exception);
        }

        return new ErrorResponse($responseData, $classification);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        if ($this->config->isProductionMode()) {
            return $this->config->getGenericErrorMessage();
        }

        return $this->security->sanitizeOutput($message);
    }

    private function generateErrorReference(Throwable $exception): string
    {
        return hash('sha256', sprintf(
            '%s:%s:%s',
            get_class($exception),
            $exception->getFile(),
            $exception->getLine()
        ));
    }

    private function generateDebugData(Throwable $exception): array
    {
        return [
            'type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->sanitizeStackTrace($exception->getTraceAsString())
        ];
    }

    private function sanitizeStackTrace(string $trace): string
    {
        if ($this->config->isProductionMode()) {
            return '[redacted]';
        }

        return $this->security->sanitizeOutput($trace);
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            if ($this->isAllowedContextKey($key)) {
                $sanitized[$key] = $this->sanitizeContextValue($value);
            }
        }
        
        return $sanitized;
    }

    private function isAllowedContextKey(string $key): bool
    {
        return !in_array($key, $this->config->getSensitiveKeys());
    }

    private function sanitizeContextValue($value): mixed
    {
        if (is_string($value)) {
            return $this->security->sanitizeOutput($value);
        }
        
        if (is_array($value)) {
            return $this->sanitizeContext($value);
        }
        
        return $value;
    }

    private function logException(
        Throwable $exception,
        ErrorClassification $classification,
        array $context
    ): void {
        $logData = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'classification' => $classification->toArray(),
            'context' => $context
        ];

        $this->logger->log(
            $classification->getLogLevel(),
            'Exception occurred',
            $logData
        );
    }

    private function handleFailsafeError(Throwable $primary, Throwable $secondary): ErrorResponse
    {
        // Log both exceptions
        $this->logger->logEmergency('Failsafe error handling activated', [
            'primary_exception' => [
                'type' => get_class($primary),
                'message' => $primary->getMessage()
            ],
            'secondary_exception' => [
                'type' => get_class($secondary),
                'message' => $secondary->getMessage()
            ]
        ]);

        // Return safe fallback response
        return new ErrorResponse([
            'status' => 'error',
            'code' => 500,
            'message' => $this->config->getFailsafeErrorMessage(),
            'reference' => hash('sha256', uniqid('failsafe', true))
        ], new ErrorClassification('critical'));
    }
}
