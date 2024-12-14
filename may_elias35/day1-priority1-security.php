<?php
namespace App\Core;

class SecurityCore implements CriticalSecurityInterface 
{
    private AuthManager $auth;
    private AccessControl $access;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function validateCriticalOperation(Operation $op): Result
    {
        DB::beginTransaction();
        try {
            // Security validation
            $this->validateSecurity($op);
            
            // Execute with protection
            $result = $this->executeProtected($op);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            return $result;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function validateSecurity(Operation $op): void
    {
        // Authenticate
        $user = $this->auth->authenticate($op->getContext());
        
        // Validate permissions
        if (!$this->access->hasPermission($user, $op->getPermissions())) {
            throw new SecurityException('Access denied');
        }

        // Log operation
        $this->audit->logOperation($op);
    }

    private function executeProtected(Operation $op): Result
    {
        return Monitor::track(fn() => $op->execute());
    }
}

class AuthManager
{
    private TokenValidator $tokens;
    private UserProvider $users;
    
    public function authenticate(Context $ctx): User
    {
        // Validate token
        $token = $this->tokens->validate($ctx->getToken());
        
        // Get user
        $user = $this->users->find($token->getUserId());
        if (!$user->isActive()) {
            throw new AuthenticationException();
        }

        return $user;
    }
}

class AccessControl
{
    private RoleManager $roles;
    private PermissionRegistry $permissions;

    public function hasPermission(User $user, array $required): bool
    {
        foreach ($required as $permission) {
            if (!$this->validatePermission($user, $permission)) {
                return false;
            }
        }
        return true;  
    }
}
