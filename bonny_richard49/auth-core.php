<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use App\Core\Exceptions\AuthenticationException;

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        try {
            DB::beginTransaction();

            // Validate credentials with zero-tolerance policy
            $validatedData = $this->validateCredentials($credentials);
            
            // Check for existing authentication attempts
            $this->checkAttempts($credentials['email']);
            
            // Attempt password verification with timing attack protection
            $user = $this->verifyCredentials($validatedData);
            
            // Generate secure session with all required protections
            $session = $this->generateSecureSession($user);
            
            // Log successful authentication
            $this->auditLogger->logAuthentication($user->id, true);
            
            DB::commit();
            
            return new AuthResult(
                true,
                $session,
                $user
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failed attempt with full context
            $this->auditLogger->logAuthenticationFailure(
                $credentials['email'],
                $e->getMessage(),
                request()->ip()
            );
            
            throw new AuthenticationException(
                'Authentication failed',
                previous: $e
            );
        }
    }

    public function validateMFA(string $userId, string $code): bool 
    {
        // Validate MFA code with timing attack protection
        return $this->security->validateMFAToken($userId, $code);
    }

    public function logout(string $userId): void 
    {
        DB::transaction(function() use ($userId) {
            // Invalidate all active sessions
            $this->invalidateSessions($userId);
            
            // Clear security context
            $this->security->clearContext($userId);
            
            // Log logout event
            $this->auditLogger->logLogout($userId);
        });
    }

    private function validateCredentials(array $credentials): array
    {
        $rules = [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ];

        return $this->validator->validate($credentials, $rules);
    }

    private function checkAttempts(string $email): void
    {
        $attempts = $this->getRecentAttempts($email);
        
        if ($attempts >= 3) {
            $this->auditLogger->logExcessiveAttempts($email);
            throw new AuthenticationException('Too many attempts');
        }
    }

    private function verifyCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    private function generateSecureSession(User $user): string 
    {
        return $this->security->createSecureSession(
            $user->id,
            request()->ip(),
            request()->userAgent()
        );
    }

    private function getRecentAttempts(string $email): int
    {
        return DB::table('auth_attempts')
            ->where('email', $email)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();
    }

    private function invalidateSessions(string $userId): void
    {
        DB::table('sessions')
            ->where('user_id', $userId)
            ->delete();
    }
}
