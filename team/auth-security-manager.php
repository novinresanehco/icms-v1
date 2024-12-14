<?php

namespace App\Core\Security\Authentication;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\Interfaces\AuthenticationInterface;
use App\Core\Security\Events\{AuthenticationEvent, SecurityEvent};
use App\Core\Security\Exceptions\{AuthenticationException, SecurityException};

class AuthenticationManager implements AuthenticationInterface 
{
    private TokenService $tokenService;
    private SecurityAudit $audit;
    private array $config;
    private int $maxAttempts = 3;
    private int $lockoutTime = 900; // 15 minutes

    public function __construct(
        TokenService $tokenService,
        SecurityAudit $audit,
        array $config
    ) {
        $this->tokenService = $tokenService;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        DB::beginTransaction();
        
        try {
            $this->validateAttempts($credentials['ip']);
            
            if (!$user = $this->validateCredentials($credentials)) {
                throw new AuthenticationException('Invalid credentials');
            }

            $mfaResult = $this->validateMFA($user, $credentials['mfa_token'] ?? null);
            if (!$mfaResult->isValid()) {
                throw new AuthenticationException('MFA validation failed');
            }

            $token = $this->generateSecureToken($user);
            $this->audit->logSuccessfulAuth($user);
            
            DB::commit();
            return new AuthResult(true, $user, $token);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $credentials);
            throw $e;
        }
    }

    public function validateSession(string $token): SessionValidation 
    {
        try {
            $session = $this->tokenService->validate($token);
            
            if (!$session->isValid()) {
                throw new SecurityException('Invalid session');
            }

            if ($this->isSessionExpired($session)) {
                throw new SecurityException('Session expired');
            }

            if ($this->detectAnomalies($session)) {
                $this->audit->logAnomalyDetected($session);
                throw new SecurityException('Security anomaly detected');
            }

            $this->extendSession($session);
            return new SessionValidation(true, $session);

        } catch (\Exception $e) {
            $this->handleSessionFailure($e, $token);
            throw $e;
        }
    }

    protected function validateAttempts(string $ip): void 
    {
        $attempts = Cache::get("auth_attempts:$ip", 0);
        
        if ($attempts >= $this->maxAttempts) {
            $this->audit->logExcessiveAttempts($ip);
            throw new SecurityException('Too many authentication attempts');
        }

        Cache::increment("auth_attempts:$ip");
        Cache::put("auth_attempts:$ip", $attempts + 1, $this->lockoutTime);
    }

    protected function validateCredentials(array $credentials): ?User 
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if ($user->isLocked() || $user->isDisabled()) {
            $this->audit->logBlockedAccess($user);
            throw new SecurityException('Account access blocked');
        }

        return $user;
    }

    protected function validateMFA(User $user, ?string $token): MFAValidation 
    {
        if (!$this->config['mfa_required']) {
            return new MFAValidation(true);
        }

        return $this->tokenService->validateMFAToken($user, $token);
    }

    protected function generateSecureToken(User $user): string 
    {
        return $this->tokenService->generate([
            'user_id' => $user->id,
            'permissions' => $user->permissions,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    protected function isSessionExpired(Session $session): bool 
    {
        return $session->created_at->addMinutes(
            $this->config['session_lifetime']
        )->isPast();
    }

    protected function detectAnomalies(Session $session): bool 
    {
        return $session->ip !== request()->ip() ||
               $session->user_agent !== request()->userAgent();
    }

    protected function extendSession(Session $session): void 
    {
        if ($this->config['sliding_sessions']) {
            $session->touch();
        }
    }

    protected function handleAuthFailure(\Exception $e, array $credentials): void 
    {
        $this->audit->logAuthFailure($e, $credentials);
        
        if ($e instanceof SecurityException) {
            event(new SecurityEvent($e, $credentials));
        }
    }

    protected function handleSessionFailure(\Exception $e, string $token): void 
    {
        $this->audit->logSessionFailure($e, $token);
        $this->tokenService->invalidate($token);
    }
}
