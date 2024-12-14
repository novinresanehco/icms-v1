<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Exceptions\SecurityException;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private LoggerInterface $logger;
    private array $config;

    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    private const TOKEN_LIFETIME = 3600;
    private const REQUIRED_STRENGTH = 4;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        LoggerInterface $logger
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->logger = $logger;
        $this->config = config('security');
    }

    public function validateAccess(SecurityContext $context): bool
    {
        try {
            $this->checkRateLimit($context);
            $this->validateToken($context->getToken());
            $this->verifyPermissions($context);
            $this->logAccess($context);
            return true;
        } catch (\Exception $e) {
            $this->handleSecurityFailure($e, $context);
            return false;
        }
    }

    public function generateToken(array $claims): string
    {
        try {
            $token = $this->encryption->encrypt(json_encode($claims));
            Cache::put($this->getTokenKey($token), $claims, self::TOKEN_LIFETIME);
            return $token;
        } catch (\Exception $e) {
            $this->logger->error('Token generation failed', [
                'error' => $e->getMessage(),
                'claims' => $claims
            ]);
            throw new SecurityException('Token generation failed', 0, $e);
        }
    }

    public function validateToken(string $token): bool
    {
        try {
            $claims = Cache::get($this->getTokenKey($token));
            if (!$claims) {
                throw new SecurityException('Invalid or expired token');
            }

            $decrypted = json_decode(
                $this->encryption->decrypt($token),
                true
            );

            if (!$this->validateClaims($decrypted, $claims)) {
                throw new SecurityException('Token validation failed');
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Token validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function revokeToken(string $token): void
    {
        Cache::forget($this->getTokenKey($token));
    }

    public function validatePassword(string $password): bool
    {
        return $this->validator->validatePasswordStrength($password) >= self::REQUIRED_STRENGTH;
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }

    private function checkRateLimit(SecurityContext $context): void
    {
        $key = 'rate_limit:' . $context->getIdentifier();
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new SecurityException('Rate limit exceeded');
        }

        Cache::put($key, $attempts + 1, self::LOCKOUT_TIME);
    }

    private function verifyPermissions(SecurityContext $context): void
    {
        if (!$this->accessControl->hasPermission(
            $context->getUser(),
            $context->getRequiredPermission()
        )) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function validateClaims(array $decrypted, array $stored): bool
    {
        return $decrypted['sub'] === $stored['sub'] &&
               $decrypted['exp'] > time() &&
               $decrypted['jti'] === $stored['jti'];
    }

    private function getTokenKey(string $token): string
    {
        return 'token:' . hash('sha256', $token);
    }

    private function handleSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logSecurityEvent(
            'security_failure',
            [
                'error' => $e->getMessage(),
                'context' => $context->toArray(),
                'trace' => $e->getTraceAsString()
            ]
        );

        if ($e instanceof SecurityException) {
            throw $e;
        }

        throw new SecurityException('Security check failed', 0, $e);
    }

    private function logAccess(SecurityContext $context): void
    {
        $this->auditLogger->logSecurityEvent(
            'access_granted',
            [
                'user' => $context->getUser()->getId(),
                'permission' => $context->getRequiredPermission(),
                'ip' => $context->getIpAddress(),
                'timestamp' => time()
            ]
        );
    }
}
