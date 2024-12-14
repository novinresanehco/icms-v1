<?php

namespace App\Core\Security;

class AuthenticationManager
{
    private EncryptionService $encryption;
    private TokenValidator $tokenValidator;
    private AuditLogger $logger;

    public function authenticate(Request $request): AuthResult
    {
        try {
            $token = $this->tokenValidator->validate($request->token());
            $user = $this->validateUser($token->user());
            
            $this->logger->logAuthentication($user);
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            $this->logger->logAuthFailure($e);
            throw new AuthenticationException($e->getMessage());
        }
    }

    private function validateUser(User $user): User
    {
        if (!$user->isActive()) {
            throw new InactiveUserException();
        }

        if ($user->requiresMFA() && !$this->validateMFA($user)) {
            throw new MFARequiredException();
        }

        return $user;
    }
}

class AccessControl
{
    private PermissionValidator $validator;
    private RoleManager $roleManager;
    private AuditLogger $logger;

    public function validateAccess(User $user, Resource $resource): void
    {
        if (!$this->hasPermission($user, $resource)) {
            $this->logger->logAccessDenied($user, $resource);
            throw new AccessDeniedException();
        }

        $this->logger->logAccessGranted($user, $resource);
    }

    private function hasPermission(User $user, Resource $resource): bool
    {
        $roles = $user->getRoles();
        $required = $resource->getRequiredPermissions();
        
        return $this->validator->validate($roles, $required);
    }
}

class EncryptionService
{
    private string $key;
    private string $algorithm;

    public function encrypt(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            $data,
            $this->algorithm,
            $this->key,
            0,
            $iv
        );

        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string
    {
        $decoded = base64_decode($data);
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        return openssl_decrypt(
            $encrypted,
            $this->algorithm,
            $this->key,
            0,
            $iv
        );
    }
}
