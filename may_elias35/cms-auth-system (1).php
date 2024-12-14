<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Auth\Services\{TokenService, AuthValidationService};
use App\Core\Auth\Events\{LoginEvent, LogoutEvent, AuthFailedEvent};
use Illuminate\Support\Facades\{Hash, Event, Cache};
use App\Core\Auth\Models\User;
use App\Core\Auth\Exceptions\{AuthenticationException, LockoutException};

class AuthenticationManager
{
    private SecurityManager $security;
    private TokenService $tokenService;
    private AuthValidationService $validator;
    private int $maxAttempts = 3;
    private int $lockoutTime = 900; // 15 minutes

    public function __construct(
        SecurityManager $security,
        TokenService $tokenService,
        AuthValidationService $validator
    ) {
        $this->security = $security;
        $this->tokenService = $tokenService;
        $this->validator = $validator;
    }

    /**
     * Authenticate user with multi-factor verification
     */
    public function authenticate(array $credentials): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processAuthentication($credentials),
            ['action' => 'authenticate', 'credentials' => $credentials]
        );
    }

    /**
     * Process secure multi-factor authentication
     */
    private function processAuthentication(array $credentials): array
    {
        // Validate credentials
        if (!$this->validator->validateCredentials($credentials)) {
            throw new AuthenticationException('Invalid credentials format');
        }

        // Check rate limiting
        $this->checkRateLimiting($credentials['email']);

        // Verify primary credentials
        $user = $this->verifyPrimaryCredentials($credentials);

        // Verify MFA if enabled
        if ($user->mfa_enabled) {
            $this->verifyMFAToken($user, $credentials['mfa_token'] ?? null);
        }

        // Generate secure tokens
        $tokens = $this->generateSecureTokens($user);

        // Log successful authentication
        Event::dispatch(new LoginEvent($user));

        return [
            'user' => $user->only(['id', 'email', 'name', 'roles']),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token']
        ];
    }

    /**
     * Verify primary login credentials
     */
    private function verifyPrimaryCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedAttempt($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    /**
     * Verify MFA token
     */
    private function verifyMFAToken(User $user, ?string $token): void
    {
        if (!$token || !$this->tokenService->verifyMFAToken($user, $token)) {
            throw new AuthenticationException('Invalid MFA token');
        }
    }

    /**
     * Generate secure access and refresh tokens
     */
    private function generateSecureTokens(User $user): array
    {
        return [
            'access_token' => $this->tokenService->generateAccessToken($user),
            'refresh_token' => $this->tokenService->generateRefreshToken($user)
        ];
    }

    /**
     * Rate limiting implementation
     */
    private function checkRateLimiting(string $email): void
    {
        $attempts = (int)Cache::get("login_attempts:{$email}", 0);

        if ($attempts >= $this->maxAttempts) {
            throw new LockoutException(
                'Too many login attempts. Please try again later.',
                $this->lockoutTime
            );
        }
    }

    /**
     * Handle failed authentication attempt
     */
    private function handleFailedAttempt(string $email): void
    {
        $attempts = Cache::increment("login_attempts:{$email}", 1);
        Cache::put("login_attempts:{$email}", $attempts, $this->lockoutTime);

        if ($attempts >= $this->maxAttempts) {
            Event::dispatch(new AuthFailedEvent($email, true));
            throw new LockoutException(
                'Too many login attempts. Please try again later.',
                $this->lockoutTime
            );
        }

        Event::dispatch(new AuthFailedEvent($email, false));
    }

    /**
     * Refresh authentication token
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokenService->refreshAccessToken($refreshToken),
            ['action' => 'refresh_token']
        );
    }

    /**
     * Invalidate all user tokens and log out
     */
    public function logout(User $user): void
    {
        $this->security->executeCriticalOperation(
            function() use ($user) {
                $this->tokenService->revokeAllTokens($user);
                Event::dispatch(new LogoutEvent($user));
            },
            ['action' => 'logout', 'user_id' => $user->id]
        );
    }

    /**
     * Validate active token
     */
    public function validateToken(string $token): ?User
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokenService->validateAccessToken($token),
            ['action' => 'validate_token']
        );
    }
}
