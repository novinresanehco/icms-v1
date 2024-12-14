<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Security\{SecurityManager, TokenService};
use App\Core\Auth\Models\User;

class AuthenticationManager
{
    private SecurityManager $security;
    private TokenService $tokens;

    public function __construct(SecurityManager $security, TokenService $tokens)
    {
        $this->security = $security;
        $this->tokens = $tokens;
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            DB::beginTransaction();

            $user = $this->validateCredentials($credentials);
            if (!$user) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->tokens->generate($user);
            $this->logSuccessfulAuth($user);

            DB::commit();
            return new AuthResult($user, $token);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logFailedAuth($credentials, $e);
            throw $e;
        }
    }

    public function validateSession(string $token): bool
    {
        if (!$this->tokens->verify($token)) {
            return false;
        }

        $session = $this->tokens->decode($token);
        return !$this->isSessionExpired($session);
    }

    public function refreshToken(string $token): string
    {
        DB::beginTransaction();
        try {
            if (!$this->tokens->verify($token)) {
                throw new AuthenticationException('Invalid token');
            }

            $user = $this->tokens->getUserFromToken($token);
            $newToken = $this->tokens->generate($user);

            DB::commit();
            return $newToken;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function logout(string $token): void
    {
        $this->tokens->revoke($token);
    }

    private function validateCredentials(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }

    private function isSessionExpired(array $session): bool
    {
        return $session['exp'] < time();
    }

    private function logSuccessfulAuth(User $user): void
    {
        Log::info('Successful authentication', [
            'user_id' => $user->id,
            'ip' => request()->ip()
        ]);
    }

    private function logFailedAuth(array $credentials, \Exception $e): void
    {
        Log::warning('Failed authentication', [
            'email' => $credentials['email'],
            'ip' => request()->ip(),
            'error' => $e->getMessage()
        ]);
    }
}

class TokenService
{
    private string $key;
    private int $expiry;

    public function generate(User $user): string
    {
        $payload = [
            'sub' => $user->id,
            'roles' => $user->roles->pluck('name'),
            'exp' => time() + $this->expiry
        ];

        return JWT::encode($payload, $this->key);
    }

    public function verify(string $token): bool
    {
        try {
            JWT::decode($token, $this->key, ['HS256']);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function decode(string $token): array
    {
        return (array) JWT::decode($token, $this->key, ['HS256']);
    }

    public function getUserFromToken(string $token): User
    {
        $payload = $this->decode($token);
        return User::findOrFail($payload['sub']);
    }

    public function revoke(string $token): void
    {
        Cache::put($this->getRevokeKey($token), true, $this->expiry);
    }

    private function getRevokeKey(string $token): string
    {
        return 'revoked_token:' . hash('sha256', $token);
    }
}