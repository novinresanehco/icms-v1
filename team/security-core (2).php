namespace App\Core\Security;

class SecurityManager
{
    private AuthManager $auth;
    private AccessControl $access;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function __construct(
        AuthManager $auth, 
        AccessControl $access,
        AuditLogger $audit,
        ValidationService $validator
    ) {
        $this->auth = $auth;
        $this->access = $access;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    public function validateOperation(SecurityContext $context): void 
    {
        try {
            // Pre-operation validation
            $this->validateRequest($context);
            
            // Validate authentication
            $this->auth->validateAuthentication($context);
            
            // Check authorization
            $this->access->checkPermissions($context);
            
            // Log successful validation
            $this->audit->logValidation($context);
            
        } catch (\Exception $e) {
            $this->audit->logFailure($e, $context);
            throw $e;
        }
    }

    public function executeSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Validate operation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->monitorExecution($operation, $context);
            
            // Verify result
            $this->validator->validateResult($result);
            
            DB::commit();
            $this->audit->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException($e->getMessage(), $e);
        }
    }

    protected function monitorExecution(callable $operation, SecurityContext $context): mixed
    {
        // Start monitoring
        $monitoringId = $this->startMonitoring($context);
        
        try {
            // Execute operation
            $result = $operation();
            
            // Record metrics
            $this->recordMetrics($monitoringId, $result);
            
            return $result;
            
        } finally {
            // Always stop monitoring
            $this->stopMonitoring($monitoringId);
        }
    }
    
    protected function startMonitoring(SecurityContext $context): string
    {
        return $this->audit->startOperationMonitoring([
            'user' => $context->getUser()->id,
            'action' => $context->getAction(),
            'resource' => $context->getResource(),
            'timestamp' => now()
        ]);
    }
    
    protected function stopMonitoring(string $monitoringId): void
    {
        $this->audit->stopOperationMonitoring($monitoringId);
    }
    
    protected function recordMetrics(string $monitoringId, $result): void
    {
        $this->audit->recordOperationMetrics($monitoringId, [
            'memory_peak' => memory_get_peak_usage(true),
            'execution_time' => microtime(true),
            'result_size' => is_array($result) ? count($result) : 1
        ]);
    }

    protected function handleFailure(\Exception $e, SecurityContext $context): void
    {
        // Log detailed error information
        $this->audit->logSecurityIncident([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context->toArray(),
            'severity' => SecurityIncident::CRITICAL
        ]);
    }
}
