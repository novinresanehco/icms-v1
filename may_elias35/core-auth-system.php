<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Interfaces\AuthenticationInterface;
use Illuminate\Support\Facades\{Hash, DB};

class AuthenticationSystem implements AuthenticationInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private TokenManager $tokens;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        TokenManager $tokens
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->tokens = $tokens;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processAuthentication($credentials),
            ['action' => 'authenticate', 'credentials' => $credentials]
        );
    }

    private function processAuthentication(array $credentials): AuthResult 
    {
        DB::beginTransaction();
        try {
            $user = $this->validateCredentials($credentials);
            if (!$user) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->tokens->generate($user);
            $this->cache->set("auth_token:{$token}", $user->id, 3600);

            DB::commit();
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function verify(string $token): bool 
    {
        return $this->security->executeSecureOperation(
            fn() => $this->verifyToken($token),
            ['action' => 'verify', 'token' => $token]
        );
    }

    private function verifyToken(string $token): bool 
    {
        $userId = $this->cache->get("auth_token:{$token}");
        if (!$userId) {
            return false;
        }

        return $this->tokens->validate($token);
    }

    public function invalidate(string $token): void 
    {
        $this->security->executeSecureOperation(
            fn() => $this->cache->delete("auth_token:{$token}"),
            ['action' => 'invalidate', 'token' => $token]
        );
    }

    private function validateCredentials(array $credentials): ?User 
    {
        $user = User::where('email', $credentials['email'])->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }
        return $user;
    }
}

class TokenManager 
{
    public function generate(User $user): string 
    {
        return bin2hex(random_bytes(32));
    }

    public function validate(string $token): bool 
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}

class AuthResult 
{
    public function __construct(
        public User $user,
        public string $token
    ) {}
}
