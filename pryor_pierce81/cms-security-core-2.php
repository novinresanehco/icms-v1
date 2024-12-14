<?php

namespace App\Core\Security;

class SecurityKernel implements SecurityInterface
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private ValidationService $validator;
    private AuditLogger $audit;
    private CacheManager $cache;

    public function validateRequest(Request $request): ValidationResult
    {
        $auditId = $this->audit->startOperation('validate_request');
        
        DB::beginTransaction();
        
        try {
            // Multi-layer validation
            $this->auth->validateAuthentication($request);
            $this->authz->validateAuthorization($request);
            $this->validator->validateInput($request->all());
            
            DB::commit();
            
            $this->audit->logSuccess($auditId);
            
            return new ValidationResult(true);
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            $this->audit->logFailure($auditId, $e);
            $this->handleSecurityException($e);
            
            throw $e;
        }
    }

    private function handleSecurityException(\Throwable $e): void
    {
        // Log security event
        $this->audit->logSecurityEvent($e);
        
        // Clear sensitive caches
        $this->cache->clearSecurityCache();
        
        // Lock account if needed
        if ($e instanceof AuthenticationException) {
            $this->auth->lockAccount($e->getUser());
        }
    }
}

class AuthenticationService
{
    private TokenManager $tokens;
    private UserRepository $users;
    private HashingService $hasher;

    public function validateAuthentication(Request $request): void
    {
        // Validate token
        $token = $this->tokens->validate($request->bearerToken());
        
        // Verify user
        $user = $this->users->findOrFail($token->getUserId());
        
        // Check password hash
        if (!$this->hasher->check($request->password, $user->password)) {
            throw new AuthenticationException();
        }
        
        // Verify 2FA if enabled
        if ($user->hasTwoFactor()) {
            $this->validateTwoFactor($request->twoFactorCode);
        }
    }
}

class AuthorizationService  
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;

    public function validateAuthorization(Request $request): void
    {
        // Get required permissions
        $permissions = $this->permissions->getRequired($request->route());
        
        // Verify user roles have permissions
        if (!$this->roles->hasPermissions($request->user(), $permissions)) {
            throw new AuthorizationException();
        }
    }
}

class ValidationService
{
    private array $rules = [];
    private Validator $validator;

    public function validateInput(array $data): void
    {
        // Validate against rules
        if (!$this->validator->validate($data, $this->rules)) {
            throw new ValidationException();
        }
        
        // Sanitize input
        $this->sanitizeData($data);
    }

    private function sanitizeData(array &$data): void
    {
        array_walk_recursive($data, function(&$value) {
            $value = strip_tags($value);
        });
    }
}
