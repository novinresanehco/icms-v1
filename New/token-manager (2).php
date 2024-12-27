<?php

namespace App\Core\Security;

class TokenManager
{
    private string $key;
    private int $lifetime;
    private CacheManager $cache;

    public function generate(User $user): string
    {
        $payload = [
            'uid' => $user->id,
            'iat' => time(),
            'exp' => time() + $this->lifetime
        ];

        $token = JWT::encode($payload, $this->key);
        $this->cache->set("token:{$token}", $user->id, $this->lifetime);
        
        return $token;
    }

    public function validate(string $token): User
    {
        try {
            $payload = JWT::decode($token, $this->key);
            
            if ($payload->exp < time()) {
                throw new TokenExpiredException();
            }
            
            if (!$this->cache->get("token:{$token}")) {
                throw new TokenInvalidatedException();
            }
            
            return User::findOrFail($payload->uid);
            
        } catch (\Exception $e) {
            throw new TokenException('Invalid token', 0, $e);
        }
    }

    public function invalidate(string $token): void
    {
        $this->cache->invalidate("token:{$token}");
    }
}

class TokenException extends \Exception {}
class TokenExpiredException extends TokenException {}
class TokenInvalidatedException extends TokenException {}
