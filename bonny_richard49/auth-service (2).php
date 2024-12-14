<?php

namespace App\Core\Services;

use App\Core\Interfaces\AuthServiceInterface;
use App\Core\Security\SecurityManager;
use App\Core\System\CacheService;
use Psr\Log\LoggerInterface;
use App\Core\Exceptions\AuthException;
use Illuminate\Support\Facades\Hash;

class AuthService implements AuthServiceInterface
{
    private SecurityManager $security;
    private CacheService $cache;
    private LoggerInterface $logger;
    private array $config;

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900;
    private const TOKEN_LIFETIME = 3600;
    private const REFRESH_TOKEN_LIFETIME = 86400;

    public function __construct(
        SecurityManager $security,
        CacheService $cache,
        LoggerInterface $logger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = config('auth');
    }

    public function authenticate(array $credentials): array
    {
        try {
            $this->validateCredentials($credentials);
            $this->checkRateLimit($credentials['email']);

            $user = $this->findUser($credentials['email']);
            
            if (!$user || !$this->verifyPassword($user, $credentials['password'])) {
                $this->handleFailedAttempt($credentials['email']);
                throw new AuthException('Invalid credentials');
            }

            if (!$user->isActive()) {
                throw new AuthException('Account is inactive');
            }

            $this->clearFailedAttempts($credentials['email']);
            
            return $this->generateTokens($user);

        } catch (\Exception $e) {
            $this->handleError('Authentication failed', $e);
        }
    }

    public function validateToken(string $token): bool
    {
        try {
            return $this->security->validateToken($token);
        } catch (\Exception $e) {
            $this->handleError('Token validation failed', $e);
        }
    }

    public function refreshToken(string $refreshToken): array
    {
        try {
            if (!$this->validateRefreshToken($refreshToken)) {
                throw new AuthException('Invalid refresh token');
            }

            $userId = $this->extractUserIdFromToken($refreshToken);
            $user = $this->findUserById($userId);

            if (!$user) {
                throw new AuthException('User not found');
            }

            return $this->generateTokens($user);

        } catch (\Exception $e) {
            $this->handleError('Token refresh failed', $e);
        }
    }

    public function invalidateTokens(int $userId): void
    {
        try {
            $this->cache->tags(['auth', "user:$userId"])->flush();
        } catch (\Exception $e) {
            $this->handleError('Token invalidation failed', $e);
        }
    }

    public function resetPassword(string $email): bool
    {
        try {
            $user = $this->findUser($email);
            
            if (!$user) {
                throw new AuthException('User not found');
            }

            $token = $this->security->generateToken([
                'type' => 'password_reset',
                'user_id' => $user->id,
                'exp' => time() + 3600
            ]);

            // Send reset email implementation
            event(new PasswordResetRequested($user, $token));
            
            return true;

        } catch (\Exception $e) {
            $this->handleError('Password reset failed', $e);
        }
    }

    public function validateResetToken(string $token): bool
    {
        try {
            return $this->security->validateToken($token);
        } catch (\Exception $e) {
            $this->handleError('Reset token validation failed', $e);
        }
    }

    private function validateCredentials(array $credentials): void
    {
        $validator = app(ValidationService::class);
        
        $rules = [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ];

        $validator->validateData($credentials, $rules);
    }

    private function checkRateLimit(string $identifier): void
    {
        $attempts = $this->getFailedAttempts($identifier);

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new AuthException('Too many failed attempts');
        }
    }

    private function handleFailedAttempt(string $identifier): void
    {
        $attempts = $this->incrementFailedAttempts($identifier);

        if ($attempts >= self::MAX_ATTEMPTS) {
            event(new AccountLocked($identifier));
        }
    }

    private function getFailedAttempts(string $identifier): int
    {
        return (int) $this->cache->get("auth.failed:$identifier", 0);
    }

    private function incrementFailedAttempts(string $identifier): int
    {
        $attempts = $this->getFailedAttempts($identifier) + 1;
        $this->cache->put("auth.failed:$identifier", $attempts, self::LOCKOUT_TIME);
        return $attempts;
    }

    private function clearFailedAttempts(string $identifier): void
    {
        $this->cache->forget("auth.failed:$identifier");
    }

    private function findUser(string $email)
    {
        return User::where('email', $email)->first();
    }

    private function findUserById(int $id)
    {
        return User::find($id);
    }

    private function verifyPassword($user, string $password): bool
    {
        return Hash::check($password, $user->password);
    }

    private function generateTokens($user): array
    {
        $accessToken = $this->security->generateToken([
            'sub' => $user->id,
            'type' => 'access',
            'exp' => time() + self::TOKEN_LIFETIME
        ]);

        $refreshToken = $this->security->generateToken([
            'sub' => $user->id,
            'type' => 'refresh',
            'exp' => time() + self::REFRESH_TOKEN_LIFETIME
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::TOKEN_LIFETIME
        ];
    }

    private function validateRefreshToken(string $token): bool
    {
        $payload = $this->security->validateToken($token);
        return $payload['type'] === 'refresh';
    }

    private function extractUserIdFromToken(string $token): int
    {
        $payload = $this->security->validateToken($token);
        return $payload['sub'];
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new AuthException($message, 0, $e);
    }
}
