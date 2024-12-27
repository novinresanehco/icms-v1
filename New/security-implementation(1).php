<?php

namespace App\Core\Security;

class AuthenticationManager implements AuthenticationInterface
{
    private TokenManager $tokens;
    private EncryptionService $encryption;
    private AuditLogger $audit;

    public function authenticate(array $credentials): AuthToken
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
            $this->audit->logFailedLogin($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->requires_2fa) {
            return $this->initiate2FA($user);
        }

        return $this->issueToken($user);
    }

    public function verify2FA(User $user, string $code): AuthToken
    {
        if (!$this->tokens->verify2FACode($user, $code)) {
            $this->audit->log2FAFailed($user);
            throw new AuthenticationException('Invalid 2FA code');
        }

        return $this->issueToken($user);
    }

    private function issueToken(User $user): AuthToken
    {
        $token = $this->tokens->issue(
            $user,
            ['ip' => request()->ip()]
        );

        $this->audit->logSuccessfulLogin($user);
        return $token;
    }

    private function verifyPassword(string $input, string $hash): bool
    {
        return password_verify($input, $hash);
    }
}

class AuthorizationManager implements AuthorizationInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $audit;

    public function authorize(AuthToken $token, string $permission): bool
    {
        $user = $token->user();

        if (!$this->permissions->exists($permission)) {
            throw new AuthorizationException('Invalid permission');
        }

        if (!$this->roles->hasPermission($user->role, $permission)) {
            $this->audit->logUnauthorizedAccess($user, $permission);
            return false;
        }

        $this->audit->logAuthorizedAccess($user, $permission);
        return true;
    }

    public function validateAccess(AuthToken $token, Model $resource): bool
    {
        $permission = $this->permissions->forResource($resource);
        return $this->authorize($token, $permission);
    }
}

class SecurityManager implements SecurityManagerInterface
{
    private AuthenticationManager $auth;
    private AuthorizationManager $authz;
    private EncryptionService $encryption;
    private TokenManager $tokens;
    private AuditLogger $audit;

    public function validateRequest(Request $request): ValidationResult
    {
        $token = $this->tokens->validate($request->bearerToken());
        
        if (!$token) {
            throw new AuthenticationException('Invalid token');
        }

        if ($permission = $request->route()->getPermission()) {
            if (!$this->authz->authorize($token, $permission)) {
                throw new AuthorizationException('Unauthorized access');
            }
        }

        return new ValidationResult(true);
    }

    public function generateChecksum(array $data): string
    {
        return hash_hmac('sha256', serialize($data), config('app.key'));
    }

    public function verifyChecksum(Model $model): bool
    {
        $original = $model->checksum;
        $calculated = $this->generateChecksum($model->toArray());
        
        return hash_equals($original, $calculated);
    }
}

class TokenManager
{
    private EncryptionService $encryption;
    private CacheManager $cache;

    public function issue(User $user, array $context = []): AuthToken
    {
        $payload = [
            'user_id' => $user->id,
            'context' => $context,
            'expires' => now()->addMinutes(config('auth.token_ttl'))
        ];

        $token = $this->encryption->encrypt(json_encode($payload));
        
        $this->cache->put(
            $this->getCacheKey($token),
            $payload,
            config('auth.token_ttl')
        );

        return new AuthToken($token, $payload);
    }

    public function validate(string $token): ?AuthToken
    {
        try {
            $payload = $this->cache->get($this->getCacheKey($token));
            
            if (!$payload || now()->gt($payload['expires'])) {
                return null;
            }

            return new AuthToken($token, $payload);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getCacheKey(string $token): string
    {
        return 'token:' . hash('sha256', $token);
    }
}
