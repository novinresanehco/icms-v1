<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Hash, Cache};

class AuthenticationManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processAuthentication($credentials),
            ['context' => 'authentication']
        );
    }

    public function validateToken(string $token): bool
    {
        $key = "auth_token_{$token}";
        $data = Cache::get($key);
        
        if (!$data) {
            return false;
        }

        return !$this->isTokenExpired($data['expires_at']);
    }

    protected function processAuthentication(array $credentials): AuthResult
    {
        $user = $this->findUser($credentials['email']);
        
        if (!$user || !$this->validatePassword($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($this->config['mfa_required'] && !$this->validateMfa($credentials['mfa_code'] ?? null, $user)) {
            throw new AuthenticationException('MFA required');
        }

        $token = $this->generateToken($user);
        $this->storeToken($token, $user);

        return new AuthResult($token, $user);
    }

    protected function validatePassword(string $input, string $hash): bool
    {
        return Hash::check($input, $hash);
    }

    protected function validateMfa(?string $code, User $user): bool
    {
        if (!$code) {
            return false;
        }
        
        return $this->verifyMfaCode($code, $user->mfa_secret);
    }

    protected function generateToken(User $user): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function storeToken(string $token, User $user): void
    {
        $key = "auth_token_{$token}";
        $data = [
            'user_id' => $user->id,
            'created_at' => time(),
            'expires_at' => time() + $this->config['token_lifetime']
        ];
        
        Cache::put($key, $data, $this->config['token_lifetime']);
    }

    protected function isTokenExpired(int $expiresAt): bool
    {
        return time() > $expiresAt;
    }
}

class AuthorizationManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function authorize(User $user, string $permission): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->checkPermission($user, $permission),
            ['context' => 'authorization']
        );
    }

    protected function checkPermission(User $user, string $permission): bool
    {
        $roles = $this->getUserRoles($user);
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    protected function getUserRoles(User $user): array
    {
        $key = "user_roles_{$user->id}";
        return Cache::remember($key, 3600, fn() => $user->roles()->get()->toArray());
    }

    protected function roleHasPermission(array $role, string $permission): bool
    {
        $key = "role_permissions_{$role['id']}";
        $permissions = Cache::remember($key, 3600, fn() => $this->loadRolePermissions($role['id']));
        return in_array($permission, $permissions);
    }
}

class SessionManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function startSession(User $user): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->createSession($user),
            ['context' => 'session_start']
        );
    }

    public function validateSession(string $sessionId): bool
    {
        $key = "session_{$sessionId}";
        $session = Cache::get($key);
        
        if (!$session) {
            return false;
        }

        if ($this->isSessionExpired($session['expires_at'])) {
            Cache::forget($key);
            return false;
        }

        $this->extendSession($sessionId, $session);
        return true;
    }

    protected function createSession(User $user): string
    {
        $sessionId = $this->generateSessionId();
        $key = "session_{$sessionId}";
        
        $data = [
            'user_id' => $user->id,
            'created_at' => time(),
            'expires_at' => time() + $this->config['session_lifetime'],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
        
        Cache::put($key, $data, $this->config['session_lifetime']);
        return $sessionId;
    }

    protected function extendSession(string $sessionId, array $session): void
    {
        $key = "session_{$sessionId}";
        $session['expires_at'] = time() + $this->config['session_lifetime'];
        Cache::put($key, $session, $this->config['session_lifetime']);
    }

    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function isSessionExpired(int $expiresAt): bool
    {
        return time() > $expiresAt;
    }
}
