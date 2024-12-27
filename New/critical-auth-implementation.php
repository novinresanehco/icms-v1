<?php

namespace App\Core\Security;

/**
 * Critical Security Manager - Core security infrastructure
 * MUST BE VALIDATED BY SECURITY TEAM
 */
class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    private MetricsCollector $metrics;
    private SecurityConfig $config;

    public function validateRequest(Request $request): ValidationResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validatePreConditions($request);
            
            // Execute core validation with monitoring
            $result = $this->executeValidation($request);
            
            // Verify final state
            $this->verifyValidationResult($result);
            
            DB::commit();
            
            // Record metrics
            $this->metrics->record('security.validation', microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $request);
            throw $e;
        }
    }

    public function executeProtectedOperation(callable $operation, Context $context): mixed
    {
        $this->validateContext($context);
        $monitorId = $this->metrics->startOperation();
        
        try {
            $result = $this->executeWithProtection($operation, $context);
            $this->metrics->recordSuccess($monitorId);
            return $result;
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            throw $e;
        }
    }

    private function validatePreConditions(Request $request): void
    {
        // Validate request integrity
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid request format');
        }

        // Check rate limiting
        if (!$this->checkRateLimit($request)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        // Verify basic security requirements
        if (!$this->verifySecurityRequirements($request)) {
            throw new SecurityException('Security requirements not met');
        }
    }

    private function executeValidation(Request $request): ValidationResult
    {
        // Execute core validation logic
        $token = $this->validateToken($request->getToken());
        $user = $this->validateUser($token);
        $permissions = $this->validatePermissions($user, $request->getResource());

        return new ValidationResult([
            'token' => $token,
            'user' => $user,
            'permissions' => $permissions
        ]);
    }

    private function handleValidationFailure(\Exception $e, Request $request): void
    {
        // Log the failure
        $this->audit->logFailure($e, [
            'request' => $request,
            'timestamp' => time(),
            'trace' => $e->getTraceAsString()
        ]);

        // Record metrics
        $this->metrics->incrementFailureCount(get_class($e));

        // Execute failure-specific protocols
        $this->executeFailureProtocols($e);
    }

    private function executeFailureProtocols(\Exception $e): void
    {
        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e);
        } elseif ($e instanceof ValidationException) {
            $this->handleValidationFailure($e);
        } else {
            $this->handleSystemFailure($e);
        }
    }
}

/**
 * Enhanced Authentication Service with comprehensive security controls
 */
class EnhancedAuthenticationService extends AuthenticationService
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function attempt(array $credentials): bool
    {
        $context = new AuthenticationContext($credentials);
        
        return $this->security->executeProtectedOperation(
            fn() => parent::attempt($credentials),
            $context
        );
    }

    protected function validateCredentials(array $credentials): ?User
    {
        // Enhanced validation
        $this->validator->validateCredentials($credentials);
        
        // Rate limiting check
        if (!$this->checkRateLimit($credentials['email'])) {
            $this->audit->logRateLimitExceeded($credentials['email']);
            throw new RateLimitException('Too many attempts');
        }

        $user = parent::validateCredentials($credentials);

        // Additional security checks
        if ($user) {
            $this->performSecurityChecks($user, $credentials);
        }

        return $user;
    }

    private function performSecurityChecks(User $user, array $credentials): void
    {
        // Check for suspicious activity
        if ($this->security->isSuspiciousActivity($user, $credentials)) {
            $this->audit->logSuspiciousActivity($user, $credentials);
            throw new SecurityException('Suspicious activity detected');
        }

        // Verify additional security requirements
        if (!$this->security->verifyLoginRequirements($user)) {
            throw new SecurityException('Additional security requirements not met');
        }
    }

    private function checkRateLimit(string $email): bool
    {
        $key = "auth.attempts:{$email}";
        $attempts = $this->cache->increment($key);
        
        return $attempts <= $this->config->get('auth.max_attempts', 5);
    }
}

/**
 * Critical Authorization Service with comprehensive access control
 */
class EnhancedAuthorizationService extends AuthorizationService
{
    private SecurityManager $security;
    private AuditLogger $audit;
    
    public function authorize(string $ability, $resource = null): bool
    {
        $context = new AuthorizationContext($ability, $resource);
        
        return $this->security->executeProtectedOperation(
            fn() => parent::authorize($ability, $resource),
            $context
        );
    }

    protected function checkPermission(User $user, string $ability, $resource = null): bool
    {
        // Enhanced permission checks
        $this->validatePermissionRequest($user, $ability, $resource);
        
        $result = parent::checkPermission($user, $ability, $resource);
        
        // Audit the access attempt
        $this->audit->logAccessAttempt($user, $ability, $resource, $result);
        
        return $result;
    }

    private function validatePermissionRequest(User $user, string $ability, $resource): void
    {
        // Validate user state
        if (!$user->isActive()) {
            throw new AuthorizationException('User account is not active');
        }

        // Validate resource access
        if ($resource && !$this->validateResourceAccess($user, $resource)) {
            throw new AuthorizationException('Invalid resource access attempt');
        }

        // Check for suspicious patterns
        if ($this->security->isSuspiciousAccessPattern($user, $ability, $resource)) {
            $this->audit->logSuspiciousAccess($user, $ability, $resource);
            throw new SecurityException('Suspicious access pattern detected');
        }
    }
}
