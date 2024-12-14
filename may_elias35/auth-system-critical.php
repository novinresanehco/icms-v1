<?php

namespace App\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Exceptions\AuthenticationException;

class AuthenticationService
{
    private TokenManager $tokens;
    private MFAHandler $mfa;
    private SecurityLogger $logger;

    public function authenticate(array $credentials): AuthResult
    {
        return DB::transaction(function() use ($credentials) {
            $user = $this->validateCredentials($credentials);
            $this->validateMFA($user, $credentials['mfa_code'] ?? null);
            $this->enforceSecurityPolicy($user);
            
            $token = $this->tokens->generate($user);
            $this->logger->logAuthentication($user);
            
            return new AuthResult($user, $token);
        });
    }

    private function validateCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->logger->logFailedAttempt($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($this->isAccountLocked($user)) {
            throw new AuthenticationException('Account locked');
        }

        return $user;
    }

    private function validateMFA(User $user, ?string $code): void
    {
        if ($this->mfa->isRequired($user) && !$this->mfa->verify($user, $code)) {
            $this->logger->logFailedMFA($user);
            throw new AuthenticationException('Invalid MFA code');
        }
    }

    private function enforceSecurityPolicy(User $user): void
    {
        if ($this->hasExceededAttempts($user)) {
            $this->lockAccount($user);
            throw new AuthenticationException('Too many attempts');
        }

        if ($this->requiresPasswordChange($user)) {
            throw new AuthenticationException('Password change required');
        }
    }

    private function hasExceededAttempts(User $user): bool
    {
        $key = "auth_attempts:{$user->id}";
        $attempts = Cache::get($key, 0);
        return $attempts >= 5;
    }

    private function lockAccount(User $user): void
    {
        Cache::put("account_locked:{$user->id}", true, 3600);
        $this->logger->logAccountLock($user);
    }

    private function isAccountLocked(User $user): bool
    {
        return Cache::get("account_locked:{$user->id}", false);
    }
}

class TokenManager
{
    private string $key;
    private int $expiration = 3600;

    public function generate(User $user): string
    {
        $payload = $this->createPayload($user);
        $token = $this->encryptPayload($payload);
        
        $this->storeToken($user->id, $token);
        return $token;
    }

    public function validate(string $token): ?User
    {
        try {
            $payload = $this->decryptToken($token);
            return $this->validatePayload($payload);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function createPayload(User $user): array
    {
        return [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('name'),
            'exp' => time() + $this->expiration
        ];
    }

    private function storeToken(int $userId, string $token): void
    {
        $key = "user_tokens:{$userId}";
        Cache::put($key, $token, $this->expiration);
    }
}

class MFAHandler
{
    private TOTPManager $totp;

    public function isRequired(User $user): bool
    {
        return $user->mfa_enabled || $user->hasRole('admin');
    }

    public function verify(User $user, ?string $code): bool
    {
        if (!$code) return false;
        return $this->totp->verify($user->mfa_secret, $code);
    }

    public function enable(User $user): string
    {
        $secret = $this->totp->generateSecret();
        $user->update(['mfa_secret' => $secret]);
        return $secret;
    }
}

class SecurityLogger
{
    public function logAuthentication(User $user): void
    {
        $this->log('authentication_success', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function logFailedAttempt(string $email): void
    {
        $this->log('authentication_failure', [
            'email' => $email,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    private function log(string $event, array $data): void
    {
        SecurityLog::create([
            'event' => $event,
            'data' => $data,
            'timestamp' => now()
        ]);
    }
}
