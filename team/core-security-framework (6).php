namespace App\Core\Security;

/**
 * Core Security Framework with Comprehensive Protection Layer
 */
class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private MetricsCollector $metrics;
    private SecurityConfig $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        MetricsCollector $metrics,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    /**
     * Executes a critical operation with comprehensive protection and monitoring
     */
    public function executeSecureOperation(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), $e->getCode(), $e);
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    /**
     * Validates operation pre-execution
     */
    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void 
    {
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Verify permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException();
        }

        // Check rate limits
        if (!$this->accessControl->checkRateLimit($context)) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException();
        }
    }

    /**
     * Executes operation with monitoring
     */
    private function executeWithProtection(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies operation result
     */
    private function verifyResult(OperationResult $result): void 
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    /**
     * Handles operation failures
     */
    private function handleFailure(CriticalOperation $operation, SecurityContext $context, \Exception $e): void 
    {
        $this->auditLogger->logOperationFailure($operation, $context, $e, [
            'stack_trace' => $e->getTraceAsString(),
            'input_data' => $operation->getData(),
            'system_state' => $this->getSystemState()
        ]);

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        $this->notifySecurityTeam($operation, $context, $e);
    }

    /**
     * Records operation metrics
     */
    private function recordMetrics(CriticalOperation $operation, float $duration): void 
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'duration' => $duration,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ]);
    }
}
