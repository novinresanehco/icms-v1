<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Services\{EncryptionService, TokenManager, AuditLogger};
use Illuminate\Support\Facades\{Hash, Cache};
use Firebase\JWT\JWT;

class AuthenticationSystem implements AuthenticationInterface
{
    private SecurityManager $security;
    private TokenManager $tokenManager;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private int $maxAttempts = 3;
    private int $lockoutTime = 900; // 15 minutes

    public function __construct(
        SecurityManager $security,
        TokenManager $tokenManager,
        EncryptionService $encryption,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->tokenManager = $tokenManager;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleAuthentication($credentials),
            ['action' => 'user_authentication', 'username' => $credentials['username']]
        );
    }

    public function validateToken(string $token): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleTokenValidation($token),
            ['action' => 'token_validation']
        );
    }

    public function refreshToken(string $refreshToken): TokenPair
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleTokenRefresh($refreshToken),
            ['action' => 'token_refresh']
        );
    }

    public function logout(string $token): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleLogout($token),
            ['action' => 'user_logout']
        );
    }

    private function handleAuthentication(array $credentials): AuthResult
    {
        // Check for account lockout
        if ($this->isAccountLocked($credentials['username'])) {
            $this->auditLogger->logAuthFailure('account_locked', $credentials['username']);
            throw new AccountLockedException('Account is temporarily locked');
        }

        // Validate credentials
        $user = $this->validateCredentials($credentials);
        if (!$user) {
            $this->handleFailedAttempt($credentials['username']);
            throw new InvalidCredentialsException('Invalid credentials provided');
        }

        // Clear failed attempts on successful login
        $this->clearFailedAttempts($credentials['username']);

        // Generate tokens
        $tokenPair = $this->generateTokenPair($user);

        // Log successful authentication
        $this->auditLogger->logAuthSuccess($user->id);

        return new AuthResult($user, $tokenPair);
    }

    private function handleTokenValidation(string $token): AuthResult
    {
        try {
            // Validate token structure and signature
            $payload = $this->tokenManager->verify($token);

            // Check if token is blacklisted
            if ($this->isTokenBlacklisted($token)) {
                throw new InvalidTokenException('Token has been revoked');
            }

            // Get user and verify status
            $user = $this->findAndVerifyUser($payload->sub);

            return new AuthResult($user, null);

        } catch (\Exception $e) {
            $this->auditLogger->logTokenValidationFailure($e->getMessage());
            throw new InvalidTokenException('Token validation failed');
        }
    }

    private function handleTokenRefresh(string $refreshToken): TokenPair
    {
        try {
            // Verify refresh token
            $payload = $this->tokenManager->verifyRefreshToken($refreshToken);

            // Get user and verify status
            $user = $this->findAndVerifyUser($payload->sub);

            // Generate new token pair
            $tokenPair = $this->generateTokenPair($user);

            // Invalidate old refresh token
            $this->tokenManager->invalidateRefreshToken($refreshToken);

            return $tokenPair;

        } catch (\Exception $e) {
            $this->auditLogger->logTokenRefreshFailure($e->getMessage());
            throw new InvalidTokenException('Token refresh failed');
        }
    }

    private function handleLogout(string $token): bool
    {
        // Verify token first
        $payload = $this->tokenManager->verify($token);

        // Blacklist the token
        $this->blacklistToken($token, $payload->exp);

        // Invalidate any refresh tokens
        $this->tokenManager->invalidateAllRefreshTokens($payload->sub);

        // Log logout
        $this->auditLogger->logLogout($payload->sub);

        return true;
    }

    private function generateTokenPair(User $user): TokenPair
    {
        $accessToken = $this->tokenManager->createAccessToken([
            'sub' => $user->id,
            'roles' => $user->roles,
            'permissions' => $user->permissions
        ]);

        $refreshToken = $this->tokenManager->createRefreshToken([
            'sub' => $user->id
        ]);

        return new TokenPair($accessToken, $refreshToken);
    }

    private function validateCredentials(array $credentials): ?User
    {
        $user = User::where('username', $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (!$user->isActive()) {
            throw new InactiveAccountException('Account is not active');
        }

        return $user;
    }

    private function isAccountLocked(string $username): bool
    {
        $attempts = Cache::get("login_attempts_{$username}", 0);
        return $attempts >= $this->maxAttempts;
    }

    private function handleFailedAttempt(string $username): void
    {
        $attempts = Cache::increment("login_attempts_{$username}", 1, $this->lockoutTime);
        
        if ($attempts >= $this->maxAttempts) {
            $this->auditLogger->logAccountLocked($username);
        }
    }

    private function clearFailedAttempts(string $username): void
    {
        Cache::forget("login_attempts_{$username}");
    }

    private function blacklistToken(string $token, int $expiration): void
    {
        $hash = hash('sha256', $token);
        Cache::put("blacklisted_token_{$hash}", true, $expiration);
    }

    private function isTokenBlacklisted(string $token): bool
    {
        $hash = hash('sha256', $token);
        return Cache::has("blacklisted_token_{$hash}");
    }

    private function findAndVerifyUser(int $userId): User
    {
        $user = User::find($userId);
        
        if (!$user || !$user->isActive()) {
            throw new UserNotFoundException('User not found or inactive');
        }

        return $user;
    }
}
