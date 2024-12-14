<?php

namespace App\Core\Security;

class AuthManager implements AuthManagerInterface
{
    private EncryptionService $encryption;
    private TokenManager $tokenManager;
    private AuditLogger $auditLogger;

    public function validateRequest(Request $request): User
    {
        // Validate token
        $token = $request->bearerToken();
        if (!$token || !$this->tokenManager->verify($token)) {
            $this->auditLogger->logInvalidToken($token);
            throw new InvalidTokenException();
        }

        // Get user
        $user = $this->tokenManager->getUser($token);
        if (!$user->isActive()) {
            $this->auditLogger->logInactiveUserAttempt($user);
            throw new InactiveUserException();
        }

        // Validate 2FA if required
        if ($user->requires2FA() && !$this->validate2FA($request, $user)) {
            $this->auditLogger->log2FAFailure($user);
            throw new Invalid2FAException();
        }

        return $user;
    }

    private function validate2FA(Request $request, User $user): bool
    {
        $code = $request->get('2fa_code');
        return $this->tokenManager->verify2FA($code, $user);
    }
}

class AccessControl implements AccessControlInterface 
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $auditLogger;

    public function hasPermission(User $user, string $resource): bool
    {
        // Get user roles
        $roles = $user->getRoles();
        
        // Check each role
        foreach ($roles as $role) {
            if ($this->roles->hasPermission($role, $resource)) {
                $this->auditLogger->logPermissionGranted($user, $resource);
                return true;
            }
        }

        $this->auditLogger->logPermissionDenied($user, $resource);
        return false;
    }
}

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;

    public function encrypt(string $data): string 
    {
        return openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $this->getIV()
        );
    }

    public function decrypt(string $encrypted): string
    {
        return openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $this->getIV()
        );
    }

    private function getIV(): string
    {
        return random_bytes(openssl_cipher_iv_length($this->cipher));
    }
}
