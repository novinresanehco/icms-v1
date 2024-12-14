<?php

namespace App\Core\Auth;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Support\Facades\Hash;
use App\Core\Exceptions\AuthenticationException;

class AuthenticationManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthentication($credentials),
            ['operation' => 'user_authentication', 'context' => $this->sanitizeContext($credentials)]
        );
    }

    public function validateSession(string $token): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeSessionValidation($token),
            ['operation' => 'session_validation', 'token' => $token]
        );
    }

    public function verifyTwoFactor(string $userId, string $code): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTwoFactorVerification($userId, $code),
            ['operation' => 'two_factor_verification', 'user_id' => $userId]
        );
    }

    private function executeAuthentication(array $credentials): AuthResult
    {
        $this->validateCredentials($credentials);
        
        try {
            // Rate limiting check
            if ($this->isRateLimitExceeded($credentials['username'])) {
                throw new AuthenticationException('Rate limit exceeded');
            }

            // Find and verify user
            $user = $this->findUser($credentials['username']);
            if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
                $this->handleFailedAttempt($credentials['username']);
                throw new AuthenticationException('Invalid credentials');
            }

            // Check account status
            if (!$this->isAccountActive($user)) {
                throw new AuthenticationException('Account is not active');
            }

            // Create secure session
            $session = $this->createSecureSession($user);

            // Generate 2FA if enabled
            if ($this->isTwoFactorRequired($user)) {
                return new AuthResult([
                    'status' => AuthResult::TWO_FACTOR_REQUIRED,
                    'user_id' => $user->id,
                    'temp_token' => $session->temp_token
                ]);
            }

            // Complete authentication
            return new AuthResult([
                'status' => AuthResult::SUCCESS,
                'user' => $user,
                'token' => $session->token
            ]);

        } catch (\Exception $e) {
            $this->audit->logFailure($e, ['username' => $credentials['username']], 'authentication');
            throw $e;
        }
    }

    private function executeSessionValidation(string $token): bool
    {
        try {
            $session = Session::where('token', $token)
                            ->where('expires_at', '>', now())
                            ->first();

            if (!$session) {
                return false;
            }

            // Verify session integrity
            if (!$this->verifySessionIntegrity($session)) {
                $this->invalidateSession($session);
                return false;
            }

            // Extend session if needed
            if ($this->shouldExtendSession($session)) {
                $this->extendSession($session);
            }

            return true;

        } catch (\Exception $e) {
            $this->audit->logFailure($e, ['token' => $token], 'session_validation');
            return false;
        }
    }

    private function executeTwoFactorVerification(string $userId, string $code): bool
    {
        try {
            $user = User::findOrFail($userId);
            
            // Verify 2FA code
            if (!$this->verifyTwoFactorCode($user, $code)) {
                throw new AuthenticationException('Invalid 2FA code');
            }

            // Complete authentication process
            $this->completeTwoFactorAuth($user);
            
            return true;

        } catch (\Exception $e) {
            $this->audit->logFailure($e, ['user_id' => $userId], 'two_factor_verification');
            throw $e;
        }
    }

    private function validateCredentials(array $credentials): void
    {
        $rules = [
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:8'
        ];

        if (!$this->validator->validateData($credentials, $rules)) {
            throw new AuthenticationException('Invalid credentials format');
        }
    }

    private function isRateLimitExceeded(string $username): bool
    {
        $attempts = Cache::get("auth_attempts:{$username}", 0);
        return $attempts >= $this->config['max_attempts'];
    }

    private function handleFailedAttempt(string $username): void
    {
        Cache::increment("auth_attempts:{$username}");
        Cache::expire("auth_attempts:{$username}", $this->config['lockout_duration']);
    }

    private function verifyPassword(string $provided, string $stored): bool
    {
        return Hash::check($provided, $stored);
    }

    private function isAccountActive(User $user): bool
    {
        return $user->status === 'active' && !$user->locked_at;
    }

    private function createSecureSession(User $user): Session
    {
        return Session::create([
            'user_id' => $user->id,
            'token' => $this->generateSecureToken(),
            'expires_at' => now()->addMinutes($this->config['session_lifetime']),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    private function verifySessionIntegrity(Session $session): bool
    {
        return $session->ip_address === request()->ip() &&
               $session->user_agent === request()->userAgent();
    }

    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function sanitizeContext(array $context): array
    {
        return array_diff_key($context, ['password' => '']);
    }

    private function invalidateSession(Session $session): void
    {
        $session->delete();
        Cache::tags('sessions')->forget("session:{$session->token}");
    }

    private function shouldExtendSession(Session $session): bool
    {
        $threshold = now()->addMinutes($this->config['session_extend_threshold']);
        return $session->expires_at <= $threshold;
    }

    private function extendSession(Session $session): void
    {
        $session->expires_at = now()->addMinutes($this->config['session_lifetime']);
        $session->save();
        Cache::tags('sessions')->put(
            "session:{$session->token}",
            $session,
            $this->config['session_lifetime']
        );
    }

    private function isTwoFactorRequired(User $user): bool
    {
        return $user->two_factor_enabled;
    }

    private function verifyTwoFactorCode(User $user, string $code): bool
    {
        return $user->verifyTwoFactorCode($code);
    }

    private function completeTwoFactorAuth(User $user): void
    {
        $user->two_factor_verified_at = now();
        $user->save();
    }
}