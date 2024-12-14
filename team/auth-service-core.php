<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    TokenService,
    AuditService
};
use App\Core\Exceptions\{
    AuthenticationException,
    AuthorizationException
};

class AuthenticationService implements AuthenticationInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private TokenService $tokenService;
    private AuditService $audit;
    
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minutes
    private const TOKEN_TTL = 3600; // 1 hour

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        TokenService $tokenService,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->tokenService = $tokenService;
        $this->audit = $audit;
    }

    public function authenticate(array $credentials): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthentication($credentials),
            ['action' => 'auth.authenticate']
        );
    }

    protected function executeAuthentication(array $credentials): array
    {
        $this->validateCredentials($credentials);
        $this->checkAttempts($credentials['email']);

        try {
            $user = User::where('email', $credentials['email'])->first();
            
            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                $this->handleFailedAttempt($credentials['email']);
                throw new AuthenticationException('Invalid credentials');
            }

            if (!$user->is_active) {
                throw new AuthenticationException('Account is disabled');
            }

            // Generate tokens
            $accessToken = $this->tokenService->generateAccessToken($user);
            $refreshToken = $this->tokenService->generateRefreshToken($user);

            // Clear failed attempts
            Cache::forget("login.attempts.{$credentials['email']}");

            // Audit successful login
            $this->audit->log('auth.success', [
                'user_id' => $user->id,
                'ip' => request()->ip()
            ]);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => self::TOKEN_TTL
            ];

        } catch (\Exception $e) {
            $this->audit->log('auth.failed', [
                'email' => $credentials['email'],
                'ip' => request()->ip(),
                'reason' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function validateToken(string $token): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTokenValidation($token),
            ['action' => 'auth.validate_token']
        );
    }

    protected function executeTokenValidation(string $token): array
    {
        try {
            $payload = $this->tokenService->validateToken($token);
            
            $user = User::findOrFail($payload['sub']);
            
            if (!$user->is_active) {
                throw new AuthorizationException('Account is disabled');
            }

            return [
                'user_id' => $user->id,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name')
            ];

        } catch (\Exception $e) {
            $this->audit->log('auth.token_invalid', [
                'token' => substr($token, 0, 10) . '...',
                'ip' => request()->ip(),
                'reason' => $e->getMessage()
            ]);
            throw new AuthenticationException('Invalid token');
        }
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTokenRefresh($refreshToken),
            ['action' => 'auth.refresh_token']
        );
    }

    protected function executeTokenRefresh(string $refreshToken): array
    {
        try {
            $payload = $this->tokenService->validateRefreshToken($refreshToken);
            
            $user = User::findOrFail($payload['sub']);
            
            if (!$user->is_active) {
                throw new AuthorizationException('Account is disabled');
            }

            $accessToken = $this->tokenService->generateAccessToken($user);
            $newRefreshToken = $this->tokenService->generateRefreshToken($user);

            // Audit token refresh
            $this->audit->log('auth.token_refresh', [
                'user_id' => $user->id,
                'ip' => request()->ip()
            ]);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => self::TOKEN_TTL
            ];

        } catch (\Exception $e) {
            $this->audit->log('auth.refresh_failed', [
                'token' => substr($refreshToken, 0, 10) . '...',
                'ip' => request()->ip(),
                'reason' => $e->getMessage()
            ]);
            throw new AuthenticationException('Invalid refresh token');
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        $valid = $this->validator->validate($credentials, [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ]);

        if (!$valid) {
            throw new AuthenticationException('Invalid credentials format');
        }
    }

    protected function checkAttempts(string $email): void
    {
        $attempts = (int) Cache::get("login.attempts.{$email}", 0);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new AuthenticationException(
                'Too many failed attempts. Account is locked for 15 minutes.'
            );
        }
    }

    protected function handleFailedAttempt(string $email): void
    {
        $attempts = (int) Cache::get("login.attempts.{$email}", 0) + 1;
        Cache::put(
            "login.attempts.{$email}",
            $attempts,
            now()->addSeconds(self::LOCKOUT_TIME)
        );
    }
}
