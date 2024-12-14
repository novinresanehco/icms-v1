<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Hash, Log};
use App\Core\Exceptions\{AuthenticationException, ValidationException};

class AuthenticationService 
{
    protected AccessControlService $accessControl;
    protected AuditLogger $auditLogger;
    
    public function __construct(
        AccessControlService $accessControl,
        AuditLogger $auditLogger
    ) {
        $this->accessControl = $accessControl;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): bool 
    {
        try {
            DB::beginTransaction();
            
            // Validate credentials
            if (!$this->validateCredentials($credentials)) {
                throw new ValidationException('Invalid credentials format');
            }

            // Attempt authentication
            $user = $this->verifyUser($credentials);
            if (!$user) {
                $this->handleFailedAttempt($credentials);
                throw new AuthenticationException('Authentication failed');
            }

            // Check MFA if required
            if ($user->requiresMfa()) {
                $this->verifyMfaToken($user, $credentials['mfa_token'] ?? null);
            }

            // Create session and audit log
            $this->establishSession($user);
            $this->auditLogger->logSuccessfulLogin($user);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logAuthenticationError($e, $credentials);
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): bool 
    {
        $required = ['email', 'password'];
        return !empty(array_filter($required, fn($field) => 
            isset($credentials[$field]) && !empty($credentials[$field])
        ));
    }

    protected function verifyUser(array $credentials): ?User 
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (!$this->accessControl->isAccountActive($user)) {
            throw new AuthenticationException('Account inactive or locked');
        }

        return $user;
    }

    protected function verifyMfaToken(User $user, ?string $token): void 
    {
        if (!$token || !$this->accessControl->validateMfaToken($user, $token)) {
            throw new AuthenticationException('Invalid MFA token');
        }
    }

    protected function establishSession(User $user): void 
    {
        $session = $this->accessControl->createSession($user);
        $this->auditLogger->logSessionCreated($user, $session);
    }

    protected function handleFailedAttempt(array $credentials): void 
    {
        $this->accessControl->recordFailedAttempt($credentials);
        
        if ($this->accessControl->isAccountLocked($credentials['email'])) {
            $this->auditLogger->logAccountLocked($credentials['email']);
            throw new AuthenticationException('Account locked due to failed attempts');
        }
    }
}
