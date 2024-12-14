namespace App\Core\Error;

class ErrorManager implements ErrorHandlerInterface
{
    private SecurityManager $security;
    private MonitoringService $monitor;
    private AuditLogger $logger;
    private RecoveryManager $recovery;
    private NotificationService $notifier;
    private array $config;

    public function handleException(\Throwable $e, array $context = []): ErrorResponse 
    {
        return $this->security->executeCriticalOperation(
            new HandleErrorOperation($e),
            function() use ($e, $context) {
                // Log error with full context
                $this->logError($e, $context);
                
                // Monitor system impact
                $impact = $this->assessImpact($e);
                
                // Execute recovery procedures if needed
                if ($impact->isCritical()) {
                    $this->executeRecovery($e, $impact);
                }
                
                // Notify relevant parties
                $this->notifyStakeholders($e, $impact);
                
                // Generate safe response
                return $this->generateResponse($e, $impact);
            }
        );
    }

    public function registerHandler(string $exceptionType, callable $handler): void 
    {
        $this->security->executeCriticalOperation(
            new RegisterHandlerOperation($exceptionType),
            function() use ($exceptionType, $handler) {
                $this->validateHandler($handler);
                $this->handlers[$exceptionType] = $handler;
                $this->logger->logHandlerRegistration($exceptionType);
            }
        );
    }

    protected function logError(\Throwable $e, array $context): void 
    {
        $this->logger->logError([
            'exception' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'context' => array_merge($context, [
                'request_id' => request()->id(),
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'inputs' => request()->except($this->config['hidden_fields']),
                'headers' => request()->headers->all(),
                'session_id' => session()->getId()
            ]),
            'system' => [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'php_version' => PHP_VERSION,
                'server' => gethostname()
            ],
            'timestamp' => microtime(true)
        ]);
    }

    protected function assessImpact(\Throwable $e): ErrorImpact 
    {
        // Check system stability
        $systemStatus = $this->monitor->checkSystemHealth();
        
        // Check error severity
        $severity = $this->determineSeverity($e);
        
        // Check affected components
        $affectedComponents = $this->identifyAffectedComponents($e);
        
        // Analyze error pattern
        $pattern = $this->analyzeErrorPattern($e);
        
        return new ErrorImpact(
            $severity,
            $systemStatus,
            $affectedComponents,
            $pattern
        );
    }

    protected function executeRecovery(\Throwable $e, ErrorImpact $impact): void 
    {
        // Start recovery transaction
        $this->recovery->begin();
        
        try {
            // Execute recovery steps
            foreach ($this->getRecoverySteps($impact) as $step) {
                $step->execute();
            }
            
            // Verify system state
            $this->verifySystemState();
            
            // Commit recovery
            $this->recovery->commit();
            
        } catch (\Exception $recoveryException) {
            // Rollback recovery
            $this->recovery->rollback();
            
            // Log recovery failure
            $this->logger->logRecoveryFailure($recoveryException);
            
            // Escalate the situation
            $this->escalateFailure($e, $recoveryException);
        }
    }

    protected function notifyStakeholders(\Throwable $e, ErrorImpact $impact): void 
    {
        $notifications = $this->prepareNotifications($e, $impact);
        
        foreach ($notifications as $notification) {
            try {
                $this->notifier->send($notification);
            } catch (\Exception $notificationException) {
                $this->logger->logNotificationFailure($notificationException);
            }
        }
    }

    protected function generateResponse(\Throwable $e, ErrorImpact $impact): ErrorResponse 
    {
        // Determine response type
        $responseType = $this->determineResponseType($e);
        
        // Prepare safe error message
        $message = $this->prepareSafeMessage($e);
        
        // Generate response data
        $data = [
            'error' => [
                'type' => $responseType,
                'message' => $message,
                'code' => $this->getPublicErrorCode($e)
            ],
            'request_id' => request()->id()
        ];
        
        // Add debug information if allowed
        if ($this->config['debug'] && auth()->user()?->isAdmin()) {
            $data['debug'] = $this->getDebugData($e);
        }
        
        return new ErrorResponse($data, $this->getHttpStatus($e));
    }

    protected function determineSeverity(\Throwable $e): string 
    {
        foreach ($this->config['severity_rules'] as $rule) {
            if ($rule->matches($e)) {
                return $rule->getSeverity();
            }
        }
        return 'error';
    }

    protected function identifyAffectedComponents(\Throwable $e): array 
    {
        $components = [];
        
        foreach ($this->config['component_mapping'] as $component => $patterns) {
            if ($this->matchesComponentPattern($e, $patterns)) {
                $components[] = $component;
            }
        }
        
        return $components;
    }

    protected function getRecoverySteps(ErrorImpact $impact): array 
    {
        return array_map(
            fn($step) => new RecoveryStep($step),
            $this->config['recovery_steps'][$impact->getSeverity()]
        );
    }

    protected function verifySystemState(): void 
    {
        $state = $this->monitor->getSystemState();
        
        if (!$state->isStable()) {
            throw new SystemUnstableException(
                'System state verification failed after recovery'
            );
        }
    }

    protected function escalateFailure(\Throwable $original, \Throwable $recovery): void 
    {
        $this->notifier->sendEmergencyNotification([
            'original_error' => $original,
            'recovery_error' => $recovery,
            'system_state' => $this->monitor->getSystemState()
        ]);
    }
}
