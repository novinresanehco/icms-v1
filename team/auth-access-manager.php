<?php

namespace App\Core\Auth;

class AuthManager implements AuthManagerInterface
{
    private UserRepository $users;
    private RoleManager $roles;
    private PermissionRegistry $permissions;
    private TokenService $tokens;
    private SecurityConfig $config;
    private AuditLogger $audit;
    private Cache $cache;

    public function __construct(
        UserRepository $users,
        RoleManager $roles,
        PermissionRegistry $permissions,
        TokenService $tokens,
        SecurityConfig $config,
        AuditLogger $audit,
        Cache $cache
    ) {
        $this->users = $users;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->tokens = $tokens;
        $this->config = $config;
        $this->audit = $audit;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $user = $this->validateCredentials($credentials);
            $this->validateMfa($user, $credentials);
            $this->validateStatus($user);

            $token = $this->tokens->generate($user);
            $this->audit->logAuthentication($user, true);

            return new AuthResult($user, $token);

        } catch (AuthException $e) {
            $this->handleFailedAuth($credentials, $e);
            throw $e;
        }
    }

    public function authorize(string $permission, ?User $user = null): bool
    {
        try {
            $user ??= $this->getCurrentUser();
            
            if (!$user) {
                throw new UnauthorizedException();
            }

            $hasPermission = $this->cache->remember(
                "auth.permission.{$user->id}.{$permission}",
                fn() => $this->checkPermission($user, $permission)
            );

            if (!$hasPermission) {
                throw new UnauthorizedException();
            }

            $this->audit->logAuthorization($user, $permission, true);
            return true;

        } catch (AuthException $e) {
            $this->audit->logAuthorization(
                $user ?? null,
                $permission,
                false,
                $e->getMessage()
            );
            throw $e;
        }
    }

    public function validateToken(string $token): AuthResult
    {
        try {
            $payload = $this->tokens->validate($token);
            $user = $this->users->findById($payload->userId);
            
            if (!$user) {
                throw new InvalidTokenException();
            }

            $this->validateStatus($user);
            return new AuthResult($user, $token);

        } catch (AuthException $e) {
            $this->audit->logTokenValidation($token, false, $e->getMessage());
            throw $e;
        }
    }

    private function validateCredentials(array $credentials): User
    {
        if (!isset($credentials['email'], $credentials['password'])) {
            throw new InvalidCredentialsException();
        }

        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user)) {
            throw new InvalidCredentialsException();
        }

        return $user;
    }

    private function validateMfa(User $user, array $credentials): void
    {
        if ($user->hasMfaEnabled()) {
            if (!isset($credentials['mfa_code'])) {
                throw new MfaRequiredException();
            }

            if (!$this->verifyMfaCode($user, $credentials['mfa_code'])) {
                throw new InvalidMfaCodeException();
            }
        }
    }

    private function validateStatus(User $user): void
    {
        if ($user->isSuspended()) {
            throw new AccountSuspendedException();
        }

        if ($user->isLocked()) {
            throw new AccountLockedException();
        }
    }

    private function checkPermission(User $user, string $permission): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($this->roles->hasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    private function handleFailedAuth(array $credentials, AuthException $e): void
    {
        $email = $credentials['email'] ?? 'unknown';
        
        $this->audit->logAuthentication(
            null,
            false,
            [
                'email' => $email,
                'error' => $e->getMessage(),
                'ip' => request()->ip()
            ]
        );

        if ($e instanceof InvalidCredentialsException) {
            $this->handleFailedAttempt($email);
        }
    }

    private function handleFailedAttempt(string $email): void
    {
        $attempts = $this->cache->increment("auth.attempts.$email");
        
        if ($attempts >= $this->config->get('max_login_attempts')) {
            $user = $this->users->findByEmail($email);
            if ($user) {
                $user->lock();
                $this->users->save($user);
                $this->audit->logAccountLocked($user);
            }
        }
    }

    private function verifyPassword(string $password, User $user): bool
    {
        return password_verify(
            $password,
            $user->getPasswordHash()
        );
    }

    private function verifyMfaCode(User $user, string $code): bool
    {
        return $user->getMfaProvider()->verify($code);
    }

    private function getCurrentUser(): ?User
    {
        return auth()->user();
    }
}
