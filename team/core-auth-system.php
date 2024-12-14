<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\{EncryptionService, ValidationService};

class AuthenticationManager
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private UserRepository $users;
    private AuditLogger $auditLogger;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        UserRepository $users,
        AuditLogger $auditLogger
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->users = $users;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        // Start transaction for atomic operations
        DB::beginTransaction();
        
        try {
            // Validate credentials structure
            $this->validator->validateCredentials($credentials);
            
            // Rate limiting check
            $this->checkRateLimit($credentials['username']);
            
            // Retrieve user and verify password
            $user = $this->users->findByUsername($credentials['username']);
            if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid credentials');
            }
            
            // Verify MFA if enabled
            if ($user->mfa_enabled) {
                $this->verifyMFAToken($credentials['mfa_token'], $user);
            }
            
            // Generate secure session token
            $token = $this->generateSecureToken($user);
            
            // Create audit trail
            $this->auditLogger->logSuccessfulLogin($user);
            
            DB::commit();
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $credentials['username']);
            throw $e;
        }
    }

    private function verifyPassword(string $provided, string $stored): bool
    {
        return Hash::check($provided, $stored);
    }

    private function verifyMFAToken(string $token, User $user): void
    {
        if (!$this->validator->validateMFAToken($token, $user)) {
            throw new MFAValidationException('Invalid MFA token');
        }
    }

    private function generateSecureToken(User $user): string
    {
        $token = $this->encryption->generateSecureToken();
        
        // Store token with user context
        Cache::put(
            "auth_token:{$token}",
            [
                'user_id' => $user->id,
                'created_at' => now(),
                'ip' => request()->ip()
            ],
            config('auth.token_ttl')
        );
        
        return $token;
    }

    private function checkRateLimit(string $username): void
    {
        $key = "auth_attempts:{$username}";
        $attempts = Cache::get($key, 0) + 1;
        
        if ($attempts > config('auth.max_attempts')) {
            throw new RateLimitException('Too many authentication attempts');
        }
        
        Cache::put($key, $attempts, now()->addMinutes(15));
    }

    private function handleAuthFailure(\Exception $e, string $username): void
    {
        $this->auditLogger->logFailedLogin($username, $e);
        
        // Additional security measures like IP banning can be implemented here
        if ($e instanceof RateLimitException) {
            $this->handleRateLimit($username);
        }
    }

    private function handleRateLimit(string $username): void
    {
        // Implement additional rate limit protections
        $this->auditLogger->logRateLimit($username);
    }

    public function validateSession(string $token): User
    {
        $session = Cache::get("auth_token:{$token}");
        
        if (!$session) {
            throw new InvalidSessionException('Session not found or expired');
        }

        if ($session['ip'] !== request()->ip()) {
            throw new SecurityException('IP address mismatch');
        }

        $user = $this->users->find($session['user_id']);
        if (!$user) {
            throw new SecurityException('User not found');
        }

        return $user;
    }

    public function logout(string $token): void
    {
        Cache::forget("auth_token:{$token}");
        $this->auditLogger->logLogout(request()->user());
    }
}
