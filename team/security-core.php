namespace App\Core\Security;

class SecurityManager 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    
    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        EncryptionService $encryption,
        AuditLogger $audit
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    public function validateAccess(Request $request): SecurityContext 
    {
        // Authenticate request
        $user = $this->auth->authenticate($request);
        
        // Check authorization
        if (!$this->authz->isAuthorized($user, $request->getResource())) {
            $this->audit->logUnauthorizedAccess($user, $request);
            throw new UnauthorizedException();
        }

        // Create security context
        $context = new SecurityContext($user, $request);
        
        // Audit successful access
        $this->audit->logAccess($context);
        
        return $context;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validatePreExecution($context);
            
            // Execute with monitoring
            $result = $this->monitorExecution($operation, $context);
            
            // Post-execution verification
            $this->verifyResult($result, $context);
            
            DB::commit();
            return $result;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validatePreExecution(SecurityContext $context): void
    {
        // Verify integrity of context
        if (!$this->encryption->verifyIntegrity($context->getData())) {
            throw new IntegrityException('Data integrity validation failed');
        }

        // Verify permissions again
        if (!$this->authz->validatePermissions($context)) {
            throw new AuthorizationException('Permission validation failed');
        }

        // Additional security checks
        $this->performSecurityChecks($context);
    }

    private function monitorExecution(callable $operation, SecurityContext $context): mixed
    {
        $startTime = microtime(true);

        try {
            $result = $operation();
            
            $this->audit->logOperation([
                'duration' => microtime(true) - $startTime,
                'context' => $context,
                'success' => true
            ]);

            return $result;

        } catch (Exception $e) {
            $this->audit->logOperation([
                'duration' => microtime(true) - $startTime,
                'context' => $context,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    private function handleFailure(Exception $e, SecurityContext $context): void
    {
        // Log detailed failure information
        $this->audit->logFailure($e, $context);

        // Implement recovery procedures if needed
        $this->executeFailureRecovery($e, $context);
    }

    private function performSecurityChecks(SecurityContext $context): void
    {
        // Rate limiting
        if (!$this->auth->checkRateLimit($context)) {
            throw new RateLimitException();
        }

        // Additional security validations
        if (!$this->authz->validateSecurityContext($context)) {
            throw new SecurityException('Security context validation failed');
        }
    }

    private function executeFailureRecovery(Exception $e, SecurityContext $context): void
    {
        // Implement specific recovery procedures based on exception type
        match (get_class($e)) {
            AuthenticationException::class => $this->auth->handleAuthFailure($e),
            AuthorizationException::class => $this->authz->handleAuthzFailure($e),
            IntegrityException::class => $this->handleIntegrityFailure($e),
            default => $this->handleGenericFailure($e)
        };
    }
}
