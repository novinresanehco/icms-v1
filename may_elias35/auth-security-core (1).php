<?php

namespace App\Core\Security;

class AuthenticationManager implements AuthInterface
{
    private AccessControl $access;
    private TokenManager $tokens;
    private AuditLogger $audit;
    private CacheManager $cache;

    public function __construct(
        AccessControl $access,
        TokenManager $tokens,
        AuditLogger $audit,
        CacheManager $cache
    ) {
        $this->access = $access;
        $this->tokens = $tokens;
        $this->audit = $audit;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult
    {
        $this->validateCredentials($credentials);
        
        DB::beginTransaction();
        try {
            $user = $this->verifyUser($credentials);
            $token = $this->tokens->generate($user);
            
            $this->audit->logAuthentication($user->id, true);
            DB::commit();
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($credentials, $e);
            throw new AuthenticationException('Authentication failed');
        }
    }

    public function verify(string $token): bool
    {
        try {
            $payload = $this->tokens->validate($token);
            $user = $this->access->getUser($payload['user_id']);
            
            if (!$this->validateSession($user, $payload)) {
                throw new SessionException('Invalid session');
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->audit->logSecurityEvent('token_verification_failed', [
                'token' => hash('sha256', $token),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function validateCredentials(array $credentials): void
    {
        $required = ['username', 'password', 'device_id'];
        foreach ($required as $field) {
            if (empty($credentials[$field])) {
                throw new ValidationException("Missing required field: $field");
            }
        }
    }

    private function verifyUser(array $credentials): User
    {
        $key = 'auth:attempts:' . $credentials['username'];
        $attempts = (int)$this->cache->get($key, 0);

        if ($attempts >= 3) {
            $this->audit->logSecurityEvent('max_attempts_exceeded', $credentials);
            throw new LockoutException('Account temporarily locked');
        }

        $user = User::where('username', $credentials['username'])->first();
        if (!$user || !$this->verifyPassword($credentials['password'], $user)) {
            $this->cache->increment($key);
            $this->cache->expire($key, 300); // 5 minutes
            throw new AuthenticationException('Invalid credentials');
        }

        $this->cache->delete($key);
        return $user;
    }

    private function verifyPassword(string $password, User $user): bool
    {
        return password_verify(
            $password . $user->salt,
            $user->password_hash
        );
    }

    private function validateSession(User $user, array $payload): bool
    {
        if ($user->security_version !== $payload['security_version']) {
            return false;
        }

        if (!$this->validateDeviceId($user, $payload['device_id'])) {
            return false;
        }

        if ($this->isSessionRevoked($payload['jti'])) {
            return false;
        }

        return true;
    }

    private function validateDeviceId(User $user, string $deviceId): bool
    {
        return $user->devices()->where('device_id', $deviceId)->exists();
    }

    private function isSessionRevoked(string $jti): bool
    {
        return $this->cache->has('revoked:' . $jti);
    }

    private function handleFailure(array $credentials, \Exception $e): void
    {
        $this->audit->logSecurityEvent('authentication_failed', [
            'username' => $credentials['username'],
            'device_id' => $credentials['device_id'],
            'error' => $e->getMessage()
        ]);
    }
}

class TokenManager
{
    private string $secretKey;
    private int $ttl;

    public function generate(User $user): string
    {
        $payload = [
            'user_id' => $user->id,
            'security_version' => $user->security_version,
            'device_id' => request()->device_id,
            'jti' => Str::uuid(),
            'iat' => time(),
            'exp' => time() + $this->ttl
        ];

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    public function validate(string $token): array
    {
        try {
            $payload = JWT::decode($token, $this->secretKey, ['HS256']);
            
            if (time() >= $payload->exp) {
                throw new TokenExpiredException();
            }
            
            return (array)$payload;
            
        } catch (\Exception $e) {
            throw new TokenValidationException($e->getMessage());
        }
    }

    public function revoke(string $token): void
    {
        $payload = $this->validate($token);
        Cache::put('revoked:' . $payload['jti'], true, $this->ttl);
    }
}

class AccessControl
{
    private RoleManager $roles;
    private PermissionRegistry $permissions;
    private AuditLogger $audit;

    public function hasPermission(User $user, string $permission): bool
    {
        try {
            $this->validateUserStatus($user);
            $result = $this->checkPermission($user, $permission);
            
            $this->audit->logAccessCheck($user->id, $permission, $result);
            return $result;
            
        } catch (\Exception $e) {
            $this->handleError($user, $permission, $e);
            return false;
        }
    }

    private function validateUserStatus(User $user): void
    {
        if (!$user->is_active) {
            throw new UserInactiveException();
        }

        if ($user->requires_password_change) {
            throw new PasswordChangeRequiredException();
        }
    }

    private function checkPermission(User $user, string $permission): bool
    {
        $rolePermissions = $this->roles->getPermissions($user->role_id);
        return isset($rolePermissions[$permission]);
    }

    private function handleError(User $user, string $permission, \Exception $e): void
    {
        $this->audit->logSecurityEvent('permission_check_failed', [
            'user_id' => $user->id,
            'permission' => $permission,
            'error' => $e->getMessage()
        ]);
    }
}
