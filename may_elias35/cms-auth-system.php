<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\Hash;
use App\Core\Security\CoreSecurityManager;
use App\Core\Auth\Events\{LoginEvent, LogoutEvent, AuthFailureEvent};

class AuthenticationManager implements AuthenticationInterface
{
    private CoreSecurityManager $security;
    private TokenService $tokenService;
    private SessionManager $sessionManager;
    private RateLimiter $rateLimiter;
    private UserRepository $users;
    private AuditLogger $auditLogger;

    public function __construct(
        CoreSecurityManager $security,
        TokenService $tokenService,
        SessionManager $sessionManager,
        RateLimiter $rateLimiter,
        UserRepository $users,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->tokenService = $tokenService;
        $this->sessionManager = $sessionManager;
        $this->rateLimiter = $rateLimiter;
        $this->users = $users;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->validateSecureOperation(
            fn() => $this->performAuthentication($credentials),
            new SecurityContext('authentication', $credentials)
        );
    }

    private function performAuthentication(array $credentials): AuthResult
    {
        // Rate limit check
        if (!$this->rateLimiter->attempt('auth:' . $credentials['email'])) {
            $this->auditLogger->logWarning('Rate limit exceeded for authentication', [
                'email' => $credentials['email'],
                'ip' => request()->ip()
            ]);
            throw new RateLimitException('Too many login attempts');
        }

        // Validate credentials format
        $this->validateCredentials($credentials);

        // Retrieve and verify user
        $user = $this->users->findByEmail($credentials['email']);
        if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
            $this->handleFailedLogin($credentials);
            throw new AuthenticationException('Invalid credentials');
        }

        // Verify two-factor authentication if enabled
        if ($user->hasTwoFactorEnabled()) {
            $this->verifyTwoFactor($credentials['two_factor_code'] ?? null, $user);
        }

        // Generate tokens and session
        $token = $this->tokenService->generateToken($user);
        $session = $this->sessionManager->createSession($user, $token);

        // Log successful authentication
        $this->auditLogger->logSuccess('User authenticated successfully', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
            'session_id' => $session->id
        ]);

        event(new LoginEvent($user));

        return new AuthResult($user, $token, $session);
    }

    public function logout(string $token): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->performLogout($token),
            new SecurityContext('logout', ['token' => $token])
        );
    }

    private function performLogout(string $token): void
    {
        $session = $this->sessionManager->getSessionByToken($token);
        if (!$session) {
            throw new AuthenticationException('Invalid session');
        }

        $this->sessionManager->invalidateSession($session->id);
        $this->tokenService->revokeToken($token);

        event(new LogoutEvent($session->user));

        $this->auditLogger->logInfo('User logged out successfully', [
            'user_id' => $session->user_id,
            'session_id' => $session->id
        ]);
    }

    private function validateCredentials(array $credentials): void
    {
        $rules = [
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'two_factor_code' => 'nullable|string|size:6'
        ];

        $validator = Validator::make($credentials, $rules);
        if ($validator->fails()) {
            throw new ValidationException('Invalid credentials format');
        }
    }

    private function verifyPassword(string $provided, string $stored): bool
    {
        return Hash::check($provided, $stored);
    }

    private function verifyTwoFactor(?string $code, User $user): void
    {
        if (!$code || !$this->twoFactorService->verify($code, $user)) {
            $this->handleFailedTwoFactor($user);
            throw new TwoFactorException('Invalid two-factor code');
        }
    }

    private function handleFailedLogin(array $credentials): void
    {
        $this->rateLimiter->increment('auth:' . $credentials['email']);
        
        $this->auditLogger->logWarning('Failed login attempt', [
            'email' => $credentials['email'],
            'ip' => request()->ip()
        ]);

        event(new AuthFailureEvent($credentials['email']));
    }

    private function handleFailedTwoFactor(User $user): void
    {
        $this->auditLogger->logWarning('Failed two-factor verification', [
            'user_id' => $user->id,
            'ip' => request()->ip()
        ]);

        event(new AuthFailureEvent($user->email, 'two-factor'));
    }
}
