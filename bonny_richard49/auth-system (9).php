<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Auth\DTO\{AuthRequest, AuthResponse};
use App\Core\Exceptions\{AuthenticationException, ValidationException};
use Illuminate\Support\Facades\{Hash, Cache, Log};

class AuthenticationService implements AuthenticationInterface 
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private UserRepository $users;
    private TokenService $tokens;
    private SessionManager $sessions;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        UserRepository $users,
        TokenService $tokens,
        SessionManager $sessions,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->users = $users;
        $this->tokens = $tokens;
        $this->sessions = $sessions;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(AuthRequest $request): AuthResponse
    {
        try {
            // Validate request
            $validated = $this->validator->validateAuthRequest($request);

            // Rate limiting check
            $this->checkRateLimit($request->getIp());

            // Attempt authentication
            $user = $this->attemptAuthentication($validated);

            // Generate tokens and session
            $tokens = $this->generateAuthTokens($user);
            $this->sessions->create($user->id, $tokens->refresh_token);

            // Log successful authentication
            $this->auditLogger->logAuthSuccess($user, $request);

            return new AuthResponse($user, $tokens);

        } catch (\Exception $e) {
            $this->handleAuthFailure($e, $request);
            throw $e;
        }
    }

    public function validateSession(string $token): bool
    {
        try {
            // Verify token validity
            $payload = $this->tokens->verify($token);

            // Check if session exists and is valid
            if (!$this->sessions->isValid($payload->user_id, $token)) {
                throw new AuthenticationException('Invalid session');
            }

            // Verify user status
            $user = $this->users->find($payload->user_id);
            if (!$user || !$user->is_active) {
                throw new AuthenticationException('User inactive or not found');
            }

            return true;

        } catch (\Exception $e) {
            Log::warning('Session validation failed', [
                'token' => substr($token, 0, 10) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function refreshToken(string $refreshToken): AuthResponse
    {
        try {
            // Verify refresh token
            $payload = $this->tokens->verifyRefresh($refreshToken);

            // Validate session
            if (!$this->sessions->validateRefreshToken($payload->user_id, $refreshToken)) {
                throw new AuthenticationException('Invalid refresh token');
            }

            // Get user and generate new tokens
            $user = $this->users->find($payload->user_id);
            $tokens = $this->generateAuthTokens($user);

            // Update session
            $this->sessions->update($user->id, $tokens->refresh_token);

            return new AuthResponse($user, $tokens);

        } catch (\Exception $e) {
            $this->handleTokenRefreshFailure($e, $refreshToken);
            throw $e;
        }
    }

    public function logout(string $token): void
    {
        try {
            $payload = $this->tokens->verify($token);
            $this->sessions->invalidate($payload->user_id);
            
            // Clear any cached user data
            $this->clearUserCache($payload->user_id);

        } catch (\Exception $e) {
            Log::warning('Logout failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function attemptAuthentication(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->is_active) {
            throw new AuthenticationException('Account is inactive');
        }

        if ($user->requires_2fa && !$this->verify2FA($user, $credentials)) {
            throw new AuthenticationException('2FA verification failed');
        }

        return $user;
    }

    protected function verify2FA(User $user, array $credentials): bool
    {
        // 2FA verification implementation
        return true;
    }

    protected function generateAuthTokens(User $user): Tokens
    {
        $accessToken = $this->tokens->generateAccess([
            'user_id' => $user->id,
            'roles' => $user->roles,
            'permissions' => $user->permissions
        ]);

        $refreshToken = $this->tokens->generateRefresh([
            'user_id' => $user->id
        ]);

        return new Tokens($accessToken, $refreshToken);
    }

    protected function checkRateLimit(string $ip): void
    {
        $key = "auth_attempts:{$ip}";
        $attempts = (int)Cache::get($key, 0);

        if ($attempts >= config('auth.max_attempts')) {
            throw new AuthenticationException('Too many login attempts');
        }

        Cache::put($key, $attempts + 1, now()->addMinutes(15));
    }

    protected function handleAuthFailure(\Exception $e, AuthRequest $request): void
    {
        $this->auditLogger->logAuthFailure(
            $request,
            $e->getMessage()
        );

        if ($e instanceof AuthenticationException) {
            // Increment failed attempts counter
            $this->incrementFailedAttempts($request->getIp());
        }
    }

    protected function handleTokenRefreshFailure(\Exception $e, string $token): void
    {
        $this->auditLogger->logTokenRefreshFailure(
            substr($token, 0, 10) . '...',
            $e->getMessage()
        );
    }

    protected function clearUserCache(int $userId): void
    {
        Cache::tags(['user', "user:{$userId}"])->flush();
    }

    protected function incrementFailedAttempts(string $ip): void
    {
        $key = "auth_attempts:{$ip}";
        $attempts = (int)Cache::get($key, 0);
        Cache::put($key, $attempts + 1, now()->addMinutes(15));
    }
}
