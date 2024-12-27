<?php

namespace App\Core\Services;

class AuthenticationService implements AuthenticationInterface
{
    private UserRepository $users;
    private TokenManager $tokens;
    private EncryptionService $encryption;
    private AuditLogger $logger;

    public function __construct(
        UserRepository $users,
        TokenManager $tokens,
        EncryptionService $encryption,
        AuditLogger $logger
    ) {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->encryption = $encryption;
        $this->logger = $logger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $user = $this->users->findByEmail($credentials['email']);
            
            if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->tokens->generate([
                'user_id' => $user->id,
                'role' => $user->role
            ]);

            $this->logger->logAuth('login_success', $user);
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            $this->logger->logAuth('login_failed', ['email' => $credentials['email']]);
            throw $e;
        }
    }

    public function verify(string $token): AuthResult
    {
        try {
            $payload = $this->tokens->verify($token);
            $user = $this->users->find($payload['user_id']);
            
            if (!$user) {
                throw new AuthenticationException('Invalid token');
            }

            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            $this->logger->logAuth('token_verification_failed', ['token' => $token]);
            throw $e;
        }
    }

    public function logout(string $token): void
    {
        $this->tokens->revoke($token);
        $this->logger->logAuth('logout', ['token' => $token]);
    }

    private function verifyPassword(string $password, string $hash): bool
    {
        return $this->encryption->verifyHash($password, $hash);
    }
}

class AuthorizationService implements AuthorizationInterface
{
    private RoleRepository $roles;
    private PermissionRegistry $permissions;
    private AuditLogger $logger;

    public function __construct(
        RoleRepository $roles,
        PermissionRegistry $permissions,
        AuditLogger $logger
    ) {
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->logger = $logger;
    }

    public function authorize(User $user, string $permission): bool
    {
        try {
            $role = $this->roles->find($user->role_id);
            
            if (!$role) {
                throw new AuthorizationException('Invalid role');
            }

            $hasPermission = $this->permissions->check($role, $permission);
            
            $this->logger->logAuth(
                $hasPermission ? 'permission_granted' : 'permission_denied',
                [
                    'user' => $user->id,
                    'permission' => $permission
                ]
            );

            return $hasPermission;
            
        } catch (\Exception $e) {
            $this->logger->logAuth('authorization_error', [
                'user' => $user->id,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

class AuthResult
{
    private User $user;
    private string $token;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}

interface AuthenticationInterface
{
    public function authenticate(array $credentials): AuthResult;
    public function verify(string $token): AuthResult;
    public function logout(string $token): void;
}

interface AuthorizationInterface
{
    public function authorize(User $user, string $permission): bool;
}

interface TokenManager
{
    public function generate(array $payload): string;
    public function verify(string $token): array;
    public function revoke(string $token): void;
}

interface PermissionRegistry
{
    public function check(Role $role, string $permission): bool;
}