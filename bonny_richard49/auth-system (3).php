<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Auth\Events\{AuthenticationAttempt, AuthenticationSuccess, AuthenticationFailure};
use Illuminate\Support\Facades\{Hash, Event};
use App\Core\Exceptions\{AuthenticationException, ValidationException};

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManagerInterface $security;
    private TokenManager $tokens;
    private MFAProvider $mfaProvider;
    private UserRepository $users;
    private RateLimiter $rateLimiter;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManagerInterface $security,
        TokenManager $tokens,
        MFAProvider $mfaProvider,
        UserRepository $users,
        RateLimiter $rateLimiter,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->tokens = $tokens;
        $this->mfaProvider = $mfaProvider;
        $this->users = $users;
        $this->rateLimiter = $rateLimiter;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthenticationResult
    {
        // Rate limiting check
        if (!$this->rateLimiter->attempt($credentials['ip'], 'authentication')) {
            $this->auditLogger->logRateLimitExceeded($credentials);
            throw new AuthenticationException('Too many attempts. Please try again later.');
        }

        // Log authentication attempt
        Event::dispatch(new AuthenticationAttempt($credentials));

        try {
            // Validate credentials
            $this->validateCredentials($credentials);

            // Verify primary credentials
            $user = $this->verifyPrimaryCredentials($credentials);

            // Verify MFA if enabled
            if ($user->hasMFAEnabled()) {
                $this->verifyMFAToken($user, $credentials['mfa_token'] ?? null);
            }

            // Generate authentication token
            $token = $this->tokens->generateToken($user, [
                'ip' => $credentials['ip'],
                'device' => $credentials['device'] ?? null
            ]);

            // Log successful authentication
            Event::dispatch(new AuthenticationSuccess($user));
            $this->auditLogger->logSuccessfulAuth($user, $credentials);

            return new AuthenticationResult(
                success: true,
                user: $user,
                token: $token
            );

        } catch (\Exception $e) {
            // Log failed attempt
            Event::dispatch(new AuthenticationFailure($credentials, $e));
            $this->auditLogger->logFailedAuth($credentials, $e);

            throw $e;
        }
    }

    private function validateCredentials(array $credentials): void
    {
        $required = ['email', 'password', 'ip'];
        foreach ($required as $field) {
            if (empty($credentials[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    private function verifyPrimaryCredentials(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new AuthenticationException('Account is not active');
        }

        return $user;
    }

    private function verifyMFAToken(User $user, ?string $token): void
    {
        if (!$token) {
            throw new ValidationException('MFA token is required');
        }

        if (!$this->mfaProvider->verifyToken($user, $token)) {
            throw new AuthenticationException('Invalid MFA token');
        }
    }

    public function validateSession(string $token): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokens->validateToken($token),
            new SecurityContext('session-validation', ['token' => $token])
        );
    }

    public function refreshToken(string $token): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokens->refreshToken($token),
            new SecurityContext('token-refresh', ['token' => $token])
        );
    }

    public function logout(string $token): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->tokens->revokeToken($token),
            new SecurityContext('logout', ['token' => $token])
        );
    }
}

// Token Manager Implementation
class TokenManager
{
    private const TOKEN_VALIDITY = 3600; // 1 hour
    private const REFRESH_WINDOW = 300; // 5 minutes

    private string $encryptionKey;
    private CacheInterface $cache;

    public function generateToken(User $user, array $context = []): string
    {
        $payload = [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('name'),
            'context' => $context,
            'issued_at' => time(),
            'expires_at' => time() + self::TOKEN_VALIDITY
        ];

        $token = $this->encryptPayload($payload);
        $this->cache->set("token:{$token}", $payload, self::TOKEN_VALIDITY);
        
        return $token;
    }

    public function validateToken(string $token): bool
    {
        $payload = $this->cache->get("token:{$token}");
        if (!$payload) {
            return false;
        }

        if ($payload['expires_at'] < time()) {
            $this->cache->delete("token:{$token}");
            return false;
        }

        return true;
    }

    public function refreshToken(string $token): string
    {
        $payload = $this->cache->get("token:{$token}");
        if (!$payload) {
            throw new AuthenticationException('Invalid token');
        }

        if ($payload['expires_at'] < time() - self::REFRESH_WINDOW) {
            throw new AuthenticationException('Token expired');
        }

        $this->revokeToken($token);
        return $this->generateToken(User::find($payload['user_id']), $payload['context']);
    }

    public function revokeToken(string $token): void
    {
        $this->cache->delete("token:{$token}");
    }

    private function encryptPayload(array $payload): string
    {
        $json = json_encode($payload);
        return encrypt($json, $this->encryptionKey);
    }
}

// MFA Provider Implementation
class MFAProvider
{
    private TOTPGenerator $totp;
    private BackupCodeManager $backupCodes;

    public function verifyToken(User $user, string $token): bool
    {
        // Try TOTP first
        if ($this->totp->verifyToken($user->mfa_secret, $token)) {
            return true;
        }

        // Fall back to backup codes
        return $this->backupCodes->verifyAndInvalidate($user, $token);
    }

    public function generateSecret(): string
    {
        return $this->totp->generateSecret();
    }

    public function generateBackupCodes(): array
    {
        return $this->backupCodes->generateCodes();
    }
}
