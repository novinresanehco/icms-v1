<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Security\TwoFactorAuth;
use App\Models\User;
use Exception;

class AuthenticationManager
{
    protected TokenManager $tokenManager;
    protected TwoFactorAuth $twoFactorAuth;
    protected AuthMetrics $metrics;
    protected array $config;

    protected const MAX_LOGIN_ATTEMPTS = 5;
    protected const LOGIN_LOCKOUT_TIME = 900; // 15 minutes

    public function __construct(
        TokenManager $tokenManager,
        TwoFactorAuth $twoFactorAuth,
        AuthMetrics $metrics
    ) {
        $this->tokenManager = $tokenManager;
        $this->twoFactorAuth = $twoFactorAuth;
        $this->metrics = $metrics;
        $this->config = config('auth');
    }

    public function authenticate(array $credentials): AuthResult
    {
        $startTime = microtime(true);

        try {
            // Check login attempts
            $this->checkLoginAttempts($credentials['email']);

            // Validate credentials
            $user = $this->validateCredentials($credentials);

            if (!$user) {
                $this->handleFailedLogin($credentials['email']);
                throw new AuthenticationException('Invalid credentials');
            }

            // Check if 2FA is required
            if ($this->requiresTwoFactor($user)) {
                return $this->initiateTwoFactorAuth($user);
            }

            // Generate tokens
            $tokens = $this->tokenManager->generateTokens($user);

            // Record successful login
            $this->recordSuccessfulLogin($user);

            // Track metrics
            $this->metrics->recordAuthentication($user, true, microtime(true) - $startTime);

            return new AuthResult(true, $user, $tokens);

        } catch (Exception $e) {
            $this->metrics->recordAuthentication(null, false, microtime(true) - $startTime);
            throw new AuthenticationException($e->getMessage(), 0, $e);
        }
    }

    public function validateToken(string $token): ?User
    {
        try {
            return $this->tokenManager->validateToken($token);
        } catch (Exception $e) {
            Log::warning('Token validation failed', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 10) . '...'
            ]);
            return null;
        }
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->tokenManager->refreshTokens($refreshToken);
    }

    public function logout(User $user, string $token): void
    {
        try {
            $this->tokenManager->revokeToken($token);
            $this->recordLogout($user);
        } catch (Exception $e) {
            Log::error('Logout failed', [
                'user' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw new AuthenticationException('Logout failed: ' . $e->getMessage());
        }
    }

    public function validateTwoFactorCode(User $user, string $code): bool
    {
        return $this->twoFactorAuth->validate($user, $code);
    }

    protected function validateCredentials(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (!$user->is_active) {
            throw new AuthenticationException('Account is inactive');
        }

        return $user;
    }

    protected function checkLoginAttempts(string $email): void
    {
        $key = $this->getLoginAttemptsKey($email);
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            throw new AuthenticationException(
                'Too many login attempts. Please try again later.'
            );
        }
    }

    protected function handleFailedLogin(string $email): void
    {
        $key = $this->getLoginAttemptsKey($email);
        $attempts = Cache::get($key, 0) + 1;
        
        Cache::put($key, $attempts, self::LOGIN_LOCKOUT_TIME);

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            Log::warning('Account locked due to too many login attempts', [
                'email' => $email
            ]);
        }
    }

    protected function getLoginAttemptsKey(string $email): string
    {
        return 'login_attempts:' . sha1($email);
    }

    protected function requiresTwoFactor(User $user): bool
    {
        return $user->two_factor_enabled && 
               $this->config['two_factor_enabled'] ?? false;
    }

    protected function initiateTwoFactorAuth(User $user): AuthResult
    {
        $code = $this->twoFactorAuth->generate($user);
        
        return new AuthResult(
            false,
            $user,
            null,
            AuthResult::TWO_FACTOR_REQUIRED,
            ['two_factor_token' => $code]
        );
    }

    protected function recordSuccessfulLogin(User $user): void
    {
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip()
        ]);

        Cache::forget($this->getLoginAttemptsKey($user->email));
    }

    protected function recordLogout(User $user): void
    {
        $user->update([
            'last_logout_at' => now()
        ]);
    }
}

class TokenManager
{
    protected array $config;
    protected string $secretKey;

    public function __construct()
    {
        $this->config = config('auth.tokens');
        $this->secretKey = config('app.key');
    }

    public function generateTokens(User $user): array
    {
        $accessToken = $this->generateAccessToken($user);
        $refreshToken = $this->generateRefreshToken($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->config['access_token_ttl']
        ];
    }

    public function validateToken(string $token): ?User
    {
        try {
            $payload = $this->decodeToken($token);
            
            if ($this->isTokenExpired($payload)) {
                throw new AuthenticationException('Token has expired');
            }

            if ($this->isTokenRevoked($token)) {
                throw new AuthenticationException('Token has been revoked');
            }

            return User::find($payload['sub']);
        } catch (Exception $e) {
            throw new AuthenticationException('Invalid token: ' . $e->getMessage());
        }
    }

    public function refreshTokens(string $refreshToken): array
    {
        try {
            $payload = $this->decodeToken($refreshToken);
            
            if ($this->isTokenExpired($payload)) {
                throw new AuthenticationException('Refresh token has expired');
            }

            if ($this->isTokenRevoked($refreshToken)) {
                throw new AuthenticationException('Refresh token has been revoked');
            }

            $user = User::find($payload['sub']);
            
            return $this->generateTokens($user);
        } catch (Exception $e) {
            throw new AuthenticationException('Invalid refresh token: ' . $e->getMessage());
        }
    }

    public function revokeToken(string $token): void
    {
        $key = $this->getTokenBlacklistKey($token);
        Cache::put($key, true, $this->config['blacklist_grace_period']);
    }

    protected function generateAccessToken(User $user): string
    {
        $payload = [
            'sub' => $user->id,
            'type' => 'access',
            'iat' => time(),
            'exp' => time() + $this->config['access_token_ttl'],
            'jti' => $this->generateTokenId()
        ];

        return $this->encodeToken($payload);
    }

    protected function generateRefreshToken(User $user): string
    {
        $payload = [
            'sub' => $user->id,
            'type' => 'refresh',
            'iat' => time(),
            'exp' => time() + $this->config['refresh_token_ttl'],
            'jti' => $this->generateTokenId()
        ];

        return $this->encodeToken($payload);
    }

    protected function encodeToken(array $payload): string
    {
        $header = base64_encode(json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]));

        $payload = base64_encode(json_encode($payload));
        $signature = $this->generateSignature($header, $payload);

        return "{$header}.{$payload}.{$signature}";
    }

    protected function decodeToken(string $token): array
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new AuthenticationException('Invalid token format');
        }

        [$header, $payload, $signature] = $parts;

        if ($signature !== $this->generateSignature($header, $payload)) {
            throw new AuthenticationException('Invalid token signature');
        }

        return json_decode(base64_decode($payload), true);
    }

    protected function generateSignature(string $header, string $payload): string
    {
        return hash_hmac(
            'sha256',
            "{$header}.{$payload}",
            $this->secretKey
        );
    }

    protected function isTokenExpired(array $payload): bool
    {
        return isset($payload['exp']) && $payload['exp'] < time();
    }

    protected function isTokenRevoked(string $token): bool
    {
        return Cache::has($this->getTokenBlacklistKey($token));
    }

    protected function getTokenBlacklistKey(string $token): string
    {
        return 'token_blacklist:' . hash('sha256', $token);
    }

    protected function generateTokenId(): string
    {
        return bin2hex(random_bytes(16));
    }
}

class AuthResult
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const TWO_FACTOR_REQUIRED = 'two_factor_required';

    protected bool $success;
    protected ?User $user;
    protected ?array $tokens;
    protected string $status;
    protected array $metadata;

    public function __construct(
        bool $success,
        ?User $user,
        ?array $tokens = null,
        string $status = self::SUCCESS,
        array $metadata = []
    ) {
        $this->success = $success;
        $this->user = $user;
        $this->tokens = $tokens;
        $this->status = $status;
        $this->metadata = $metadata;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getTokens(): ?array
    {
        return $this->tokens;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->status === self::TWO_FACTOR_REQUIRED;
    }
}

class AuthMetrics
{
    protected array $metrics = [];

    public function recordAuthentication(
        ?User $user,
        bool $success,
        float $duration,
        array $metadata = []
    ): void {
        $this->metrics[] = [
            'user_id' => $user?->id,
            'success' => $success,
            'duration' => $duration,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => microtime(true),
            'metadata' => $metadata
        ];
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
