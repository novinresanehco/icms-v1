<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Auth\Services\{MFAService, TokenService, SessionManager};
use App\Core\Auth\Models\User;
use Illuminate\Support\Facades\{Hash, Event};
use App\Core\Auth\Events\AuthEvent;

class AuthenticationManager implements AuthManagerInterface
{
    private SecurityManager $security;
    private MFAService $mfa;
    private TokenService $tokenService;
    private SessionManager $sessionManager;

    public function __construct(
        SecurityManager $security,
        MFAService $mfa,
        TokenService $tokenService,
        SessionManager $sessionManager
    ) {
        $this->security = $security;
        $this->mfa = $mfa;
        $this->tokenService = $tokenService;
        $this->sessionManager = $sessionManager;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleAuthentication($credentials),
            ['operation' => 'user_authentication']
        );
    }

    public function validateMFA(string $userId, string $code): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->mfa->validateCode($userId, $code),
            ['operation' => 'mfa_validation']
        );
    }

    public function logout(string $userId): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->handleLogout($userId),
            ['operation' => 'user_logout']
        );
    }

    public function refreshToken(string $token): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokenService->refreshToken($token),
            ['operation' => 'token_refresh']
        );
    }

    private function handleAuthentication(array $credentials): AuthResult
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            Event::dispatch(new AuthEvent('failed_login', ['email' => $credentials['email']]));
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->requires_mfa && !isset($credentials['mfa_code'])) {
            return new AuthResult(
                success: false,
                requiresMFA: true,
                userId: $user->id
            );
        }

        if ($user->requires_mfa) {
            $mfaValid = $this->mfa->validateCode($user->id, $credentials['mfa_code']);
            if (!$mfaValid) {
                Event::dispatch(new AuthEvent('failed_mfa', ['user_id' => $user->id]));
                throw new AuthenticationException('Invalid MFA code');
            }
        }

        $session = $this->sessionManager->createSession($user);
        $token = $this->tokenService->generateToken($user, $session->id);

        Event::dispatch(new AuthEvent('successful_login', ['user_id' => $user->id]));

        return new AuthResult(
            success: true,
            requiresMFA: false,
            userId: $user->id,
            token: $token,
            sessionId: $session->id
        );
    }

    private function handleLogout(string $userId): void
    {
        $this->sessionManager->invalidateAllSessions($userId);
        $this->tokenService->revokeAllTokens($userId);
        Event::dispatch(new AuthEvent('logout', ['user_id' => $userId]));
    }

    public function validateSession(string $sessionId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->sessionManager->validateSession($sessionId),
            ['operation' => 'session_validation']
        );
    }

    public function validateToken(string $token): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->tokenService->validateToken($token),
            ['operation' => 'token_validation']
        );
    }

    public function rotateSecrets(string $userId): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->mfa->rotateSecrets($userId),
            ['operation' => 'secret_rotation']
        );
    }
}
