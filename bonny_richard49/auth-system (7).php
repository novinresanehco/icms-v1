<?php

namespace App\Core\Auth;

class AuthenticationManager
{
    private SecurityManager $security;
    private TokenManager $tokens;
    private AuthConfig $config;
    private AuditLogger $logger;

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        try {
            $user = $this->validateCredentials($credentials);
            $token = $this->tokens->generate($user);
            $this->logger->logAuthentication($user);
            DB::commit();
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailure($e);
            throw $e;
        }
    }

    private function validateCredentials(array $credentials): User
    {
        if (!$user = User::where('email', $credentials['email'])->first()) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }
}

class TokenManager
{
    private string $key;
    private int $ttl;

    public function generate(User $user): string 
    {
        $payload = [
            'uid' => $user->id,
            'exp' => time() + $this->ttl
        ];
        return JWT::encode($payload, $this->key);
    }

    public function validate(string $token): ?User
    {
        try {
            $payload = JWT::decode($token, $this->key);
            return User::find($payload->uid);
        } catch (\Exception $e) {
            return null;
        }
    }
}

class PermissionManager
{
    private array $permissions;
    private CacheManager $cache;

    public function check(User $user, string $permission): bool
    {
        $key = "permissions.{$user->id}";
        return $this->cache->remember($key, fn() => 
            $user->permissions->contains('name', $permission)
        );
    }
}