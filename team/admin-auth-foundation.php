<?php

namespace App\Core\Admin;

use App\Core\Security\{SecurityManager, SecurityContext};
use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Exceptions\{AuthException, ValidationException};

class AdminAuthManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private TokenManager $tokenManager;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        TokenManager $tokenManager,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->tokenManager = $tokenManager;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        $context = new SecurityContext('system', 'auth', 'authenticate');

        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthentication($credentials),
            $context
        );
    }

    private function executeAuthentication(array $credentials): AuthResult
    {
        $validated = $this->validator->validate($credentials, [
            'email' => 'required|email',
            'password' => 'required|string',
            'mfa_code' => 'required|string|size:6'
        ]);

        $admin = Admin::where('email', $validated['email'])->firstOrFail();

        if (!Hash::check($validated['password'], $admin->password)) {
            $this->auditLogger->logFailedLogin($admin->email);
            throw new AuthException('Invalid credentials');
        }

        if (!$this->verifyMfaCode($admin, $validated['mfa_code'])) {
            $this->auditLogger->logFailedMfa($admin->email);
            throw new AuthException('Invalid MFA code');
        }

        $token = $this->tokenManager->generateToken($admin);
        $this->auditLogger->logSuccessfulLogin($admin);

        return new AuthResult($admin, $token);
    }

    private function verifyMfaCode(Admin $admin, string $code): bool
    {
        return $this->tokenManager->verifyMfaCode($admin->mfa_secret, $code);
    }

    public function validateSession(string $token): AdminSession
    {
        $context = new SecurityContext('system', 'session', 'validate');

        return $this->security->executeCriticalOperation(
            fn() => $this->executeSessionValidation($token),
            $context
        );
    }

    private function executeSessionValidation(string $token): AdminSession
    {
        $session = $this->tokenManager->validateToken($token);
        if (!$session) {
            throw new AuthException('Invalid or expired session');
        }

        if ($this->isSessionRevoked($session->id)) {
            throw new AuthException('Session has been revoked');
        }

        $this->extendSession($session);
        return $session;
    }

    private function isSessionRevoked(string $sessionId): bool
    {
        return Cache::has("revoked_session:{$sessionId}");
    }

    private function extendSession(AdminSession $session): void
    {
        if ($session->shouldExtend()) {
            $this->tokenManager->extendToken($session->token);
        }
    }

    public function revokeSession(string $token): void
    {
        $context = new SecurityContext('system', 'session', 'revoke');

        $this->security->executeCriticalOperation(
            fn() => $this->executeSessionRevocation($token),
            $context
        );
    }

    private function executeSessionRevocation(string $token): void
    {
        $session = $this->tokenManager->validateToken($token);
        if ($session) {
            Cache::put(
                "revoked_session:{$session->id}",
                true,
                now()->addDays(7)
            );
            $this->tokenManager->revokeToken($token);
            $this->auditLogger->logSessionRevocation($session);
        }
    }
}

class TokenManager
{
    private const TOKEN_LIFETIME = 3600;
    
    public function generateToken(Admin $admin): string
    {
        $token = bin2hex(random_bytes(32));
        
        Cache::put(
            "admin_token:{$token}",
            new AdminSession($admin, $token),
            now()->addSeconds(self::TOKEN_LIFETIME)
        );
        
        return $token;
    }

    public function validateToken(string $token): ?AdminSession
    {
        return Cache::get("admin_token:{$token}");
    }

    public function verifyMfaCode(string $secret, string $code): bool
    {
        return (new MfaValidator())->verify($secret, $code);
    }

    public function extendToken(string $token): void
    {
        if ($session = $this->validateToken($token)) {
            Cache::put(
                "admin_token:{$token}",
                $session,
                now()->addSeconds(self::TOKEN_LIFETIME)
            );
        }
    }

    public function revokeToken(string $token): void
    {
        Cache::forget("admin_token:{$token}");
    }
}

class AdminSession
{
    public function __construct(
        public readonly Admin $admin,
        public readonly string $token,
        public readonly string $id = '',
        private readonly int $createdAt = 0
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = time();
    }

    public function shouldExtend(): bool
    {
        $lifetime = time() - $this->createdAt;
        return $lifetime > (TokenManager::TOKEN_LIFETIME / 2);
    }
}

class AuthResult
{
    public function __construct(
        public readonly Admin $admin,
        public readonly string $token
    ) {}
}

class MfaValidator
{
    public function verify(string $secret, string $code): bool
    {
        // Implement MFA code validation
        return true;
    }
}
