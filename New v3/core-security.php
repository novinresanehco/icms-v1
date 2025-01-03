<?php

namespace App\Core\Security;

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManager $security;
    private SessionManager $sessions;
    private AuditLogger $audit;

    public function authenticate(array $credentials): AuthResult 
    {
        DB::beginTransaction();
        try {
            $this->validateCredentials($credentials);
            $user = $this->verifyUser($credentials);
            $this->validateAccess($user);
            
            $session = $this->sessions->create($user);
            $this->audit->logAuthentication('success', $user->id);
            
            DB::commit();
            return new AuthResult($user, $session);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $credentials);
            throw $e;
        }
    }

    private function validateAccess(User $user): void 
    {
        if ($user->isLocked()) {
            throw new AuthException('Account is locked');
        }

        if (!$user->isActive()) {
            throw new AuthException('Account is not active');
        }
    }

    private function handleAuthFailure(\Exception $e, array $credentials): void 
    {
        $this->audit->logAuthentication('failure', null, [
            'error' => $e->getMessage(),
            'username' => $credentials['username'] ?? null
        ]);
    }
}

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $crypto;
    private AuditLogger $audit;

    public function validateRequest(Request $request): SecurityContext 
    {
        $this->validateOrigin($request);
        $this->validateToken($request->bearerToken());
        $this->validatePermissions($request->user(), $request->route());
        
        return new SecurityContext($request);
    }

    public function encryptData(array $data): array 
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->crypto->encrypt($value);
        }
        return $result;
    }

    private function validateOrigin(Request $request): void 
    {
        if (!$this->validator->validateOrigin($request)) {
            $this->audit->logSecurityEvent('invalid_origin', $request);
            throw new SecurityException('Invalid request origin');
        }
    }
}

class AccessControl implements AccessControlInterface 
{
    private RoleManager $roles;
    private AuditLogger $audit;

    public function validateAccess(User $user, string $permission): bool 
    {
        if (!$this->roles->hasPermission($user, $permission)) {
            $this->audit->logAccessDenied($user->id, $permission);
            return false;
        }
        
        $this->audit->logAccess($user->id, $permission);
        return true;
    }
}
