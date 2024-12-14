<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditLogger,
    TokenService
};
use App\Core\Exceptions\{
    AuthenticationException,
    SecurityException,
    ValidationException
};

class AuthenticationManager
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private TokenService $tokenService;
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        TokenService $tokenService,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->tokenService = $tokenService;
        $this->securityConfig = $securityConfig;
    }

    public function authenticateUser(array $credentials, array $mfaData = []): array
    {
        try {
            // Validate credentials
            $this->validateCredentials($credentials);

            // Verify primary authentication
            $user = $this->verifyPrimaryAuth($credentials);

            // Enforce MFA if enabled
            if ($this->requiresMFA($user)) {
                $this->verifyMFAToken($user, $mfaData);
            }

            // Generate secure session
            $session = $this->establishSecureSession($user);

            // Log successful authentication
            $this->auditLogger->logAuthentication([
                'user_id' => $user['id'],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            return $session;

        } catch (\Exception $e) {
            $this->handleAuthenticationFailure($e, $credentials);
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        if (!$this->validator->validateAuthCredentials($credentials)) {
            throw new ValidationException('Invalid authentication credentials');
        }

        if ($this->isRateLimited($credentials)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function verifyPrimaryAuth(array $credentials): array
    {
        $user = $this->findUser($credentials);

        if (!$user || !$this->verifyPassword($credentials['password'], $user['password'])) {
            $this->incrementFailedAttempts($credentials);
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    protected function verifyMFAToken(array $user, array $mfaData): void
    {
        if (!$this->tokenService->verifyMFAToken($user['id'], $mfaData)) {
            throw new SecurityException('Invalid MFA token');
        }
    }

    protected function establishSecureSession(array $user): array
    {
        $token = $this->tokenService->generateSecureToken();
        
        $session = [
            'token' => $token,
            'user_id' => $user['id'],
            'expires_at' => time() + $this->securityConfig['session_lifetime'],
            'permissions' => $this->getUserPermissions($user)
        ];

        Cache::put(
            $this->getSessionKey($token),
            $this->encryption->encrypt(json_encode($session)),
            $this->securityConfig['session_lifetime']
        );

        return $session;
    }

    protected function handleAuthenticationFailure(\Exception $e, array $credentials): void
    {
        $this->auditLogger->logAuthenticationFailure([
            'error' => $e->getMessage(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'credentials' => $this->sanitizeCredentials($credentials)
        ]);

        if ($e instanceof AuthenticationException) {
            $this->incrementFailedAttempts($credentials);
        }
    }

    protected function isRateLimited(array $credentials): bool
    {
        $key = 'auth_attempts:' . $this->getIdentifier($credentials);
        $attempts = Cache::get($key, 0);
        
        return $attempts >= $this->securityConfig['max_attempts'];
    }

    protected function incrementFailedAttempts(array $credentials): void
    {
        $key = 'auth_attempts:' . $this->getIdentifier($credentials);
        $attempts = Cache::get($key, 0) + 1;
        
        Cache::put(
            $key,
            $attempts,
            $this->securityConfig['lockout_duration']
        );
    }

    protected function requiresMFA(array $user): bool
    {
        return $user['mfa_enabled'] || 
               $this->securityConfig['force_mfa'] || 
               $this->isHighRiskLogin();
    }

    protected function isHighRiskLogin(): bool
    {
        return $this->securityConfig['risk_detection_enabled'] &&
               $this->detectAnomalousActivity();
    }

    protected function detectAnomalousActivity(): bool
    {
        // Implement anomaly detection logic
        return false;
    }

    protected function getSessionKey(string $token): string
    {
        return 'session:' . hash('sha256', $token);
    }

    protected function getIdentifier(array $credentials): string
    {
        return hash('sha256', $credentials['email'] . request()->ip());
    }

    protected function sanitizeCredentials(array $credentials): array
    {
        return array_diff_key($credentials, array_flip(['password']));
    }
}
