<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Exceptions\{AuthenticationException, SecurityException};

class AuthenticationManager
{
    protected TokenService $tokens;
    protected SecurityConfig $config;
    protected AuditLogger $logger;

    public function authenticate(array $credentials): AuthToken
    {
        DB::beginTransaction();
        try {
            $user = $this->validateCredentials($credentials);
            $token = $this->tokens->generate($user);
            
            $this->logger->logAuthSuccess($user);
            DB::commit();
            
            return $token;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logAuthFailure($credentials, $e);
            throw new AuthenticationException('Authentication failed');
        }
    }

    private function validateCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new SecurityException('Account inactive');
        }

        return $user;
    }
}

class SecurityManager
{
    protected AccessControl $access;
    protected ValidationService $validator;
    protected RateLimiter $limiter;

    public function validateRequest(Request $request): SecurityContext
    {
        if (!$this->limiter->check($request)) {
            throw new SecurityException('Rate limit exceeded');
        }

        $token = $this->validateToken($request);
        $user = $token->getUser();

        $this->validatePermissions($user, $request->getAction());

        return new SecurityContext($user, $token);
    }

    protected function validatePermissions(User $user, string $action): void
    {
        if (!$this->access->can($user, $action)) {
            throw new SecurityException('Unauthorized action');
        }
    }
}

class AccessControl
{
    protected PermissionRegistry $permissions;
    protected RoleManager $roles;
    protected CacheManager $cache;

    public function can(User $user, string $action): bool
    {
        $key = "permissions.{$user->id}.{$action}";

        return $this->cache->remember($key, 300, function() use ($user, $action) {
            $role = $this->roles->getRole($user);
            return $this->permissions->check($role, $action);
        });
    }
}

class TokenService
{
    protected string $algorithm = 'HS256';
    protected int $ttl = 3600;

    public function generate(User $user): AuthToken
    {
        $payload = [
            'sub' => $user->id,
            'role' => $user->role,
            'exp' => time() + $this->ttl
        ];

        $token = $this->createToken($payload);
        
        return new AuthToken($token, $this->ttl);
    }

    public function validate(string $token): TokenPayload
    {
        try {
            $payload = $this->decodeToken($token);
            
            if (time() >= $payload['exp']) {
                throw new SecurityException('Token expired');
            }

            return new TokenPayload($payload);
        } catch (\Exception $e) {
            throw new SecurityException('Invalid token');
        }
    }

    protected function createToken(array $payload): string
    {
        $header = $this->base64Encode(['typ' => 'JWT', 'alg' => $this->algorithm]);
        $payload = $this->base64Encode($payload);
        $signature = $this->sign("$header.$payload");
        
        return "$header.$payload.$signature";
    }

    protected function sign(string $data): string 
    {
        return hash_hmac('sha256', $data, config('app.key'));
    }
}

class RateLimiter
{
    protected CacheManager $cache;
    protected int $maxAttempts = 60;
    protected int $decayMinutes = 1;

    public function check(Request $request): bool
    {
        $key = $this->getKey($request);
        $attempts = (int) $this->cache->get($key, 0);
        
        if ($attempts >= $this->maxAttempts) {
            return false;
        }

        $this->cache->put($key, $attempts + 1, $this->decayMinutes * 60);
        return true;
    }

    protected function getKey(Request $request): string
    {
        return 'ratelimit.' . sha1($request->ip() . '|' . $request->fingerprint());
    }
}

class ValidationService
{
    protected array $rules;
    
    public function validate(array $data, array $rules = []): array
    {
        $rules = $rules ?: $this->rules;
        
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException("Validation failed for $field");
            }
        }

        return $data;
    }

    protected function validateField($value, $rule): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL),
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            default => true
        };
    }
}
