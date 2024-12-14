<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, Log, Hash};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{AuthException, SecurityException};
use Firebase\JWT\{JWT, Key};

class AuthenticationService
{
    protected SecurityManager $security;
    protected array $config;
    protected string $algorithm = 'HS256';
    protected int $tokenExpiry;
    protected int $maxAttempts = 3;
    protected int $lockoutTime = 900;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->config = config('auth');
        $this->tokenExpiry = config('auth.token_expiry', 3600);
    }

    public function authenticate(array $credentials): array
    {
        $this->checkLoginAttempts($credentials);

        try {
            $user = $this->validateCredentials($credentials);
            
            if (!$user) {
                $this->incrementLoginAttempts($credentials);
                throw new AuthException('Invalid credentials');
            }

            if ($this->requiresMfa($user)) {
                return $this->handleMfaRequired($user);
            }

            $token = $this->generateToken($user);
            $this->clearLoginAttempts($credentials);
            $this->logSuccessfulLogin($user);

            return [
                'token' => $token,
                'user' => $this->sanitizeUserData($user),
                'expires_in' => $this->tokenExpiry
            ];

        } catch (\Exception $e) {
            $this->logFailedLogin($credentials, $e);
            throw $e;
        }
    }

    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode(
                $token, 
                new Key($this->config['jwt_secret'], $this->algorithm)
            );

            $user = $this->getUserFromToken($decoded);
            
            if (!$user) {
                throw new AuthException('Invalid token user');
            }

            if ($this->isTokenRevoked($token)) {
                throw new AuthException('Token has been revoked');
            }

            if ($this->isTokenExpired($decoded)) {
                throw new AuthException('Token has expired');
            }

            return [
                'user' => $this->sanitizeUserData($user),
                'scopes' => (array) $decoded->scopes
            ];

        } catch (\Exception $e) {
            $this->logFailedTokenValidation($token, $e);
            throw new SecurityException('Token validation failed: ' . $e->getMessage());
        }
    }

    public function verifyMfa(int $userId, string $code): bool
    {
        try {
            $user = $this->getUserById($userId);
            
            if (!$user) {
                throw new AuthException('Invalid user');
            }

            if (!$this->validateMfaCode($user, $code)) {
                $this->logFailedMfa($user);
                return false;
            }

            $this->setMfaVerified($user->id);
            $this->logSuccessfulMfa($user);
            
            return true;

        } catch (\Exception $e) {
            $this->logFailedMfa($user ?? null, $e);
            throw $e;
        }
    }

    public function logout(string $token): void
    {
        try {
            $decoded = JWT::decode(
                $token, 
                new Key($this->config['jwt_secret'], $this->algorithm)
            );

            $this->revokeToken($token);
            $this->clearUserSessions($decoded->sub);
            $this->logLogout($decoded->sub);

        } catch (\Exception $e) {
            Log::error('Logout failed', [
                'token' => substr($token, 0, 10) . '...',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): ?object
    {
        $user = $this->getUserByUsername($credentials['username']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (!$this->isUserActive($user)) {
            throw new AuthException('Account is not active');
        }

        $this->security->validateUserAccess($user);

        return $user;
    }

    protected function generateToken(object $user): string
    {
        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'scopes' => $this->getUserScopes($user),
            'iat' => time(),
            'exp' => time() + $this->tokenExpiry
        ];

        return JWT::encode($payload, $this->config['jwt_secret'], $this->algorithm);
    }

    protected function checkLoginAttempts(array $credentials): void
    {
        $key = $this->getLoginAttemptsKey($credentials);
        $attempts = Cache::get($key, 0);

        if ($attempts >= $this->maxAttempts) {
            throw new AuthException('Too many login attempts. Please try again later.');
        }
    }

    protected function incrementLoginAttempts(array $credentials): void
    {
        $key = $this->getLoginAttemptsKey($credentials);
        $attempts = Cache::get($key, 0);
        
        Cache::put($key, $attempts + 1, $this->lockoutTime);
    }

    protected function clearLoginAttempts(array $credentials): void
    {
        Cache::forget($this->getLoginAttemptsKey($credentials));
    }

    protected function getLoginAttemptsKey(array $credentials): string
    {
        return 'login_attempts:' . md5($credentials['username'] . '|' . request()->ip());
    }

    protected function isTokenRevoked(string $token): bool
    {
        return Cache::has('revoked_token:' . md5($token));
    }

    protected function revokeToken(string $token): void
    {
        $decoded = JWT::decode(
            $token, 
            new Key($this->config['jwt_secret'], $this->algorithm)
        );
        
        Cache::put(
            'revoked_token:' . md5($token),
            true,
            $decoded->exp - time()
        );
    }

    protected function isTokenExpired(object $decoded): bool
    {
        return $decoded->exp < time();
    }

    protected function requiresMfa(object $user): bool
    {
        return $user->mfa_enabled && !$this->isMfaVerified($user->id);
    }

    protected function isMfaVerified(int $userId): bool
    {
        return Cache::has('mfa_verified:' . $userId);
    }

    protected function setMfaVerified(int $userId): void
    {
        Cache::put('mfa_verified:' . $userId, true, 3600);
    }

    protected function validateMfaCode(object $user, string $code): bool
    {
        // Implementation depends on MFA method (TOTP, SMS, etc.)
        return true;
    }

    protected function sanitizeUserData(object $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->roles
        ];
    }

    protected function getUserScopes(object $user): array
    {
        return $user->roles->pluck('permissions')->flatten()->unique()->values()->toArray();
    }

    protected function clearUserSessions(int $userId): void
    {
        Cache::tags(['user_sessions:' . $userId])->flush();
    }

    // Logging methods
    protected function logSuccessfulLogin(object $user): void
    {
        Log::info('Successful login', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    protected function logFailedLogin(array $credentials, \Exception $e): void
    {
        Log::warning('Failed login attempt', [
            'username' => $credentials['username'],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }

    protected function logFailedTokenValidation(string $token, \Exception $e): void
    {
        Log::warning('Token validation failed', [
            'token_prefix' => substr($token, 0, 10) . '...',
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }

    protected function logSuccessfulMfa(object $user): void
    {
        Log::info('Successful MFA verification', [
            'user_id' => $user->id,
            'ip' => request()->ip()
        ]);
    }

    protected function logFailedMfa(?object $user, ?\Exception $e = null): void
    {
        Log::warning('Failed MFA verification', [
            'user_id' => $user->id ?? null,
            'ip' => request()->ip(),
            'error' => $e ? $e->getMessage() : 'Invalid code'
        ]);
    }

    protected function logLogout(int $userId): void
    {
        Log::info('User logged out', [
            'user_id' => $userId,
            'ip' => request()->ip()
        ]);
    }
}
