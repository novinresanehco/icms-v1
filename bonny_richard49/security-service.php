<?php

namespace App\Core\Security;

use App\Core\Interfaces\{
    SecurityServiceInterface,
    ValidationInterface,
    AuditInterface
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException
};
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class SecurityService implements SecurityServiceInterface 
{
    private ValidationInterface $validator;
    private AuditInterface $audit;
    private LoggerInterface $logger;

    private const MAX_LOGIN_ATTEMPTS = 3;
    private const LOCKOUT_DURATION = 900; // 15 minutes
    private const SESSION_TIMEOUT = 900;
    private const TOKEN_EXPIRY = 3600;
    
    public function __construct(
        ValidationInterface $validator,
        AuditInterface $audit,
        LoggerInterface $logger
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->logger = $logger;
    }

    public function validateSecurityContext(SecurityContext $context): void
    {
        try {
            DB::beginTransaction();

            $this->validateAuthentication($context);
            $this->validateAuthorization($context);
            $this->validateSession($context);
            $this->validateRequest($context);

            // Log validation
            $this->audit->logSecurityCheck($context);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityError($e, $context);
            throw $e;
        }
    }

    public function createSecurityToken(SecurityContext $context): string
    {
        try {
            DB::beginTransaction();

            // Validate context
            $this->validateSecurityContext($context);

            // Generate token
            $token = $this->generateSecureToken();

            // Store token
            $this->storeToken($token, $context);

            // Log token creation
            $this->audit->logTokenCreation($context);

            DB::commit();

            return $token;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityError($e, $context);
            throw $e;
        }
    }

    public function validateSecurityToken(string $token, SecurityContext $context): bool
    {
        try {
            // Validate token format
            if (!$this->validator->validateTokenFormat($token)) {
                throw new ValidationException('Invalid token format');
            }

            // Check token validity
            if (!$this->isTokenValid($token)) {
                throw new SecurityException('Invalid or expired token');
            }

            // Validate context with token
            $this->validateTokenContext($token, $context);

            // Log validation
            $this->audit->logTokenValidation($token, $context);

            return true;
        } catch (\Exception $e) {
            $this->handleSecurityError($e, $context);
            throw $e;
        }
    }

    protected function validateAuthentication(SecurityContext $context): void
    {
        if (!$this->validator->validateAuthCredentials($context)) {
            throw new SecurityException('Authentication validation failed');
        }

        if ($this->isAccountLocked($context)) {
            throw new SecurityException('Account is locked');
        }

        $this->updateLoginAttempts($context);
    }

    protected function validateAuthorization(SecurityContext $context): void
    {
        if (!$this->validator->validatePermissions($context)) {
            throw new SecurityException('Authorization validation failed');
        }
    }

    protected function validateSession(SecurityContext $context): void
    {
        if (!$this->validator->validateSession($context)) {
            throw new SecurityException('Session validation failed');
        }

        if ($this->isSessionExpired($context)) {
            throw new SecurityException('Session has expired');
        }
    }

    protected function validateRequest(SecurityContext $context): void
    {
        if (!$this->validator->validateRequest($context)) {
            throw new SecurityException('Request validation failed');
        }
    }

    protected function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function storeToken(string $token, SecurityContext $context): void
    {
        $expires = time() + self::TOKEN_EXPIRY;
        
        DB::table('security_tokens')->insert([
            'token' => hash('sha256', $token),
            'context_id' => $context->getId(),
            'expires_at' => $expires,
            'created_at' => time()
        ]);
    }

    protected function isTokenValid(string $token): bool
    {
        $record = DB::table('security_tokens')
            ->where('token', hash('sha256', $token))
            ->where('expires_at', '>', time())
            ->first();

        return $record !== null;
    }

    protected function validateTokenContext(string $token, SecurityContext $context): void
    {
        $record = DB::table('security_tokens')
            ->where('token', hash('sha256', $token))
            ->where('context_id', $context->getId())
            ->first();

        if (!$record) {
            throw new SecurityException('Token context mismatch');
        }
    }

    protected function isAccountLocked(SecurityContext $context): bool
    {
        $attempts = DB::table('login_attempts')
            ->where('user_id', $context->getUserId())
            ->where('created_at', '>', time() - self::LOCKOUT_DURATION)
            ->count();

        return $attempts >= self::MAX_LOGIN_ATTEMPTS;
    }

    protected function updateLoginAttempts(SecurityContext $context): void
    {
        DB::table('login_attempts')->insert([
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'created_at' => time()
        ]);
    }

    protected function isSessionExpired(SecurityContext $context): bool
    {
        $lastActivity = $context->getLastActivityTime();
        return (time() - $lastActivity) > self::SESSION_TIMEOUT;
    }

    protected function handleSecurityError(\Exception $e, SecurityContext $context): void
    {
        $this->logger->error('Security error occurred', [
            'exception' => $e->getMessage(),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->audit->logSecurityFailure($e, $context);
    }
}
