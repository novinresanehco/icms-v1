<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\AuthenticationException;

class AuthenticationService
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenManager $tokens;
    
    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        TokenManager $tokens
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->tokens = $tokens;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performAuthentication($credentials),
            ['action' => 'authenticate', 'credentials' => $credentials]
        );
    }

    private function performAuthentication(array $credentials): AuthResult
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->requires_2fa) {
            return $this->initiate2FA($user);
        }

        return $this->completeAuthentication($user);
    }

    private function completeAuthentication($user): AuthResult
    {
        $token = $this->tokens->create([
            'user_id' => $user->id,
            'abilities' => $user->permissions
        ]);

        Cache::put(
            "user_session_{$user->id}", 
            ['last_activity' => now()],
            config('auth.session_timeout')
        );

        return new AuthResult($user, $token);
    }

    public function validateToken(string $token): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokens->validate($token),
            ['action' => 'validate_token', 'token' => $token]
        );
    }

    public function logout(string $token): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->tokens->revoke($token),
            ['action' => 'logout', 'token' => $token]
        );
    }
}

class TokenManager
{
    private string $key;
    
    public function create(array $claims): string
    {
        $payload = $this->generatePayload($claims);
        return $this->encodeAndSign($payload);
    }
    
    public function validate(string $token): bool
    {
        try {
            $payload = $this->verifyAndDecode($token);
            return !$this->isExpired($payload) && !$this->isRevoked($token);
        } catch (\Exception) {
            return false;
        }
    }
    
    public function revoke(string $token): void
    {
        Cache::put("revoked_token_{$token}", true, now()->addDays(7));
    }

    private function generatePayload(array $claims): array
    {
        return array_merge($claims, [
            'iat' => time(),
            'exp' => time() + config('auth.token_ttl'),
            'jti' => bin2hex(random_bytes(16))
        ]);
    }

    private function isExpired(array $payload): bool
    {
        return time() >= ($payload['exp'] ?? 0);
    }

    private function isRevoked(string $token): bool
    {
        return Cache::has("revoked_token_{$token}");
    }

    private function encodeAndSign(array $payload): string
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "{$header}.{$payload}", $this->key);
        return "{$header}.{$payload}.{$signature}";
    }

    private function verifyAndDecode(string $token): array
    {
        [$header, $payload, $signature] = explode('.', $token);
        
        $expectedSignature = hash_hmac(
            'sha256',
            "{$header}.{$payload}",
            $this->key
        );
        
        if (!hash_equals($signature, $expectedSignature)) {
            throw new AuthenticationException('Invalid token signature');
        }

        return json_decode(base64_decode($payload), true);
    }
}

class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)
            ->with('permissions')
            ->first();
    }
}

class AuthResult
{
    public function __construct(
        public User $user,
        public string $token
    ) {}
}
