<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Event};
use App\Core\Security\SecurityManager;
use App\Core\Auth\Events\{LoginEvent, LogoutEvent, AccessDeniedEvent};
use App\Core\Exceptions\{AuthenticationException, AuthorizationException};

class AuthenticationManager implements AuthenticationInterface
{
    protected SecurityManager $security;
    protected RoleManager $roleManager;
    protected UserRepository $users;
    protected TokenManager $tokenManager;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        RoleManager $roleManager,
        UserRepository $users,
        TokenManager $tokenManager,
        array $config
    ) {
        $this->security = $security;
        $this->roleManager = $roleManager;
        $this->users = $users;
        $this->tokenManager = $tokenManager;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthToken
    {
        return $this->security->executeCriticalOperation(function() use ($credentials) {
            $user = $this->users->findByUsername($credentials['username']);
            
            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                $this->handleFailedLogin($credentials['username']);
                throw new AuthenticationException('Invalid credentials');
            }

            if ($this->isMultiFactorRequired($user)) {
                return $this->initiateMultiFactor($user);
            }

            return $this->completeAuthentication($user);
        });
    }

    public function verifyMultiFactor(string $userId, string $code): AuthToken
    {
        return $this->security->executeCriticalOperation(function() use ($userId, $code) {
            $user = $this->users->findOrFail($userId);
            
            if (!$this->tokenManager->verifyMfaToken($user, $code)) {
                $this->handleFailedMfa($user);
                throw new AuthenticationException('Invalid MFA code');
            }

            return $this->completeAuthentication($user);
        });
    }

    public function authorize(string $userId, string $permission): bool
    {
        $cacheKey = "auth.permissions.{$userId}.{$permission}";
        
        return Cache::remember($cacheKey, $this->config['permission_cache_ttl'], function() use ($userId, $permission) {
            $user = $this->users->findOrFail($userId);
            $hasPermission = $this->roleManager->hasPermission($user->role, $permission);
            
            if (!$hasPermission) {
                Event::dispatch(new AccessDeniedEvent($user, $permission));
            }
            
            return $hasPermission;
        });
    }

    public function logout(string $token): void
    {
        $this->security->executeCriticalOperation(function() use ($token) {
            $userId = $this->tokenManager->getUserFromToken($token);
            $this->tokenManager->revokeToken($token);
            
            $user = $this->users->find($userId);
            if ($user) {
                Event::dispatch(new LogoutEvent($user));
                Cache::tags(["user.{$userId}"])->flush();
            }
        });
    }

    protected function completeAuthentication(User $user): AuthToken
    {
        $token = $this->tokenManager->createToken($user);
        Event::dispatch(new LoginEvent($user));
        
        return $token;
    }

    protected function isMultiFactorRequired(User $user): bool
    {
        return $user->mfa_enabled || 
               $this->roleManager->requiresMfa($user->role) ||
               $this->detectSuspiciousLogin($user);
    }

    protected function initiateMultiFactor(User $user): PendingMfaToken
    {
        $mfaToken = $this->tokenManager->createMfaToken($user);
        $this->sendMfaCode($user, $mfaToken->code);
        return $mfaToken;
    }

    protected function handleFailedLogin(string $username): void
    {
        $attempts = Cache::increment("login.attempts.{$username}");
        
        if ($attempts >= $this->config['max_login_attempts']) {
            Cache::put(
                "login.lockout.{$username}", 
                true, 
                now()->addMinutes($this->config['lockout_duration'])
            );
        }
    }

    protected function handleFailedMfa(User $user): void
    {
        $attempts = Cache::increment("mfa.attempts.{$user->id}");
        
        if ($attempts >= $this->config['max_mfa_attempts']) {
            $this->lockAccount($user);
        }
    }

    protected function detectSuspiciousLogin(User $user): bool
    {
        // Implement suspicious login detection logic
        return false;
    }

    protected function lockAccount(User $user): void
    {
        $this->users->update($user->id, ['locked' => true]);
        // Notify security team
    }
}
