<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{AuthenticationInterface, ValidationInterface};

class AuthenticationManager implements AuthenticationInterface 
{
    private CoreSecurityManager $security;
    private ValidationInterface $validator;
    private TokenManager $tokenManager;
    private AuditLogger $auditLogger;
    private int $maxAttempts = 3;
    private int $lockoutTime = 900;

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            new AuthOperation('authenticate', $credentials, function() use ($credentials) {
                $this->checkAttempts($credentials['email']);
                $validated = $this->validator->validate($credentials);
                
                DB::beginTransaction();
                try {
                    $user = User::where('email', $validated['email'])->first();
                    
                    if (!$user || !Hash::check($validated['password'], $user->password)) {
                        $this->failedAttempt($validated['email']);
                        throw new AuthenticationException('Invalid credentials');
                    }

                    $token = $this->tokenManager->generate($user);
                    $this->setupSession($user, $token);
                    
                    DB::commit();
                    return new AuthResult($user, $token);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function validateToken(string $token): bool 
    {
        return $this->security->executeCriticalOperation(
            new AuthOperation('validate_token', ['token' => $token], function() use ($token) {
                return $this->tokenManager->validate($token);
            })
        );
    }

    public function revokeAccess(int $userId): bool 
    {
        return $this->security->executeCriticalOperation(
            new AuthOperation('revoke', ['user_id' => $userId], function() use ($userId) {
                DB::beginTransaction();
                try {
                    $this->tokenManager->revokeAll($userId);
                    $this->clearSessions($userId);
                    
                    DB::commit();
                    return true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    private function checkAttempts(string $email): void 
    {
        $attempts = Cache::get("auth_attempts:$email", 0);
        
        if ($attempts >= $this->maxAttempts) {
            throw new LockoutException("Account locked. Try again in " . ($this->lockoutTime / 60) . " minutes.");
        }
    }

    private function failedAttempt(string $email): void 
    {
        $attempts = Cache::increment("auth_attempts:$email", 1);
        
        if ($attempts >= $this->maxAttempts) {
            Cache::put("auth_attempts:$email", $attempts, $this->lockoutTime);
        }
    }

    private function setupSession(User $user, string $token): void 
    {
        session([
            'user_id' => $user->id,
            'token' => $token,
            'permissions' => $this->loadPermissions($user),
            'last_activity' => time()
        ]);
    }

    private function loadPermissions(User $user): array 
    {
        return Cache::remember(
            "user_permissions:{$user->id}",
            3600,
            fn() => $user->getAllPermissions()
        );
    }

    private function clearSessions(int $userId): void 
    {
        Cache::forget("user_permissions:$userId");
        session()->invalidate();
    }
}

class TokenManager 
{
    private string $algorithm = 'sha256';
    private int $expiration = 3600;

    public function generate(User $user): string 
    {
        $payload = [
            'user_id' => $user->id,
            'issued' => time(),
            'expires' => time() + $this->expiration
        ];

        $token = hash_hmac(
            $this->algorithm,
            json_encode($payload),
            config('app.key')
        );

        Cache::put("token:$token", $payload, $this->expiration);
        return $token;
    }

    public function validate(string $token): bool 
    {
        $payload = Cache::get("token:$token");
        
        if (!$payload) {
            return false;
        }

        if ($payload['expires'] < time()) {
            Cache::forget("token:$token");
            return false;
        }

        return true;
    }

    public function revokeAll(int $userId): void 
    {
        $pattern = "token:*";
        $keys = Cache::get($pattern);
        
        foreach ($keys as $key) {
            $payload = Cache::get($key);
            if ($payload && $payload['user_id'] === $userId) {
                Cache::forget($key);
            }
        }
    }
}

class AuthOperation implements CriticalOperation 
{
    private string $type;
    private array $data;
    private \Closure $operation;

    public function __construct(string $type, array $data, \Closure $operation) 
    {
        $this->type = $type;
        $this->data = $data;
        $this->operation = $operation;
    }

    public function execute(): mixed 
    {
        return ($this->operation)();
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getData(): array 
    {
        return $this->data;
    }
}
