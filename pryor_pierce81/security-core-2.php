<?php

namespace App\Core\Security;

use App\Core\Exception\SecurityException;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class SecurityManager implements SecurityManagerInterface
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private LoggerInterface $logger;
    private array $config;
    private array $securityCache = [];

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateSecureOperation(string $operation, array $context = []): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->validateContext($context);
            $this->verifyAuthentication();
            $this->checkAuthorization($operation, $context);
            $this->validateSecurityConstraints($operation, $context);
            $this->logSecurityCheck($operationId, $operation, $context);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($operationId, $operation, $e);
            throw new SecurityException('Security validation failed', 0, $e);
        }
    }

    public function checkContentAccess(Content $content, array $context = []): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->validateContext($context);
            $user = $this->auth->getCurrentUser();

            if (!$user) {
                throw new SecurityException('User not authenticated');
            }

            $access = $this->authz->checkContentPermission($user, $content, $context);
            $this->logAccessCheck($operationId, $content->getId(), $user->getId());

            return $access;

        } catch (\Exception $e) {
            $this->handleSecurityFailure($operationId, 'content_access', $e);
            throw new SecurityException('Content access check failed', 0, $e);
        }
    }

    public function validateContentSecurity(Content $content): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->validateContentHash($content);
            $this->validateContentMetadata($content);
            $this->validateContentPermissions($content);
            
            $this->logSecurityValidation($operationId, 'content', $content->getId());
            
            return true;

        } catch (\Exception $e) {
            $this->handleSecurityFailure($operationId, 'content_validation', $e);
            throw new SecurityException('Content security validation failed', 0, $e);
        }
    }

    public function encryptData(string $data): string
    {
        try {
            return openssl_encrypt(
                $data,
                $this->config['encryption_method'],
                $this->config['encryption_key'],
                0,
                $this->generateIV()
            );
        } catch (\Exception $e) {
            throw new SecurityException('Data encryption failed', 0, $e);
        }
    }

    public function decryptData(string $encrypted): string
    {
        try {
            return openssl_decrypt(
                $encrypted,
                $this->config['encryption_method'],
                $this->config['encryption_key'],
                0,
                $this->getIV()
            );
        } catch (\Exception $e) {
            throw new SecurityException('Data decryption failed', 0, $e);
        }
    }

    private function validateContext(array $context): void
    {
        if (!isset($context['user_id'])) {
            throw new SecurityException('Invalid security context');
        }

        if ($this->isContextBlacklisted($context)) {
            throw new SecurityException('Security context blacklisted');
        }
    }

    private function verifyAuthentication(): void
    {
        if (!$this->auth->isAuthenticated()) {
            throw new SecurityException('User not authenticated');
        }

        if ($this->auth->isSessionExpired()) {
            throw new SecurityException('Session expired');
        }
    }

    private function checkAuthorization(string $operation, array $context): void
    {
        $user = $this->auth->getCurrentUser();
        
        if (!$this->authz->hasPermission($user, $operation, $context)) {
            throw new SecurityException('Operation not authorized');
        }
    }

    private function validateSecurityConstraints(string $operation, array $context): void
    {
        if (!$this->validateOperationConstraints($operation)) {
            throw new SecurityException('Operation constraints validation failed');
        }

        if (!$this->validateContextConstraints($context)) {
            throw new SecurityException('Context constraints validation failed');
        }
    }

    private function validateContentHash(Content $content): void
    {
        $expectedHash = $this->generateContentHash($content);
        
        if (!hash_equals($expectedHash, $content->security_hash)) {
            throw new SecurityException('Content hash validation failed');
        }
    }

    private function validateContentMetadata(Content $content): void
    {
        if (!$this->isValidMetadata($content->metadata)) {
            throw new SecurityException('Content metadata validation failed');
        }
    }

    private function validateContentPermissions(Content $content): void
    {
        if (!$this->authz->validateContentPermissions($content)) {
            throw new SecurityException('Content permissions validation failed');
        }
    }

    private function generateOperationId(): string
    {
        return uniqid('security_', true);
    }

    private function generateContentHash(Content $content): string
    {
        $data = [
            'id' => $content->getId(),
            'title' => $content->title,
            'content' => $content->content,
            'version' => $content->version
        ];

        return hash_hmac('sha256', serialize($data), $this->config['security_key']);
    }

    private function getDefaultConfig(): array
    {
        return [
            'security_key' => config('app.key'),
            'encryption_method' => 'AES-256-CBC',
            'encryption_key' => config('app.key'),
            'session_lifetime' => 3600,
            'max_attempts' => 3,
            'lockout_time' => 300
        ];
    }

    private function handleSecurityFailure(string $operationId, string $operation, \Exception $e): void
    {
        $this->logger->critical('Security operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifySecurityFailure($operationId, $operation, $e);
    }
}
