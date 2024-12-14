<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Security\Auth\AuthenticationManager;
use App\Core\Security\Access\AccessControlManager;
use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Exceptions\SecurityException;
use App\Core\Exceptions\ValidationException;

class SecurityManager implements SecurityManagerInterface 
{
    private AuthenticationManager $authManager;
    private AccessControlManager $accessControl;
    private ValidationServiceInterface $validator;
    private AuditLogger $auditLogger;
    private array $securityConfig;

    public function __construct(
        AuthenticationManager $authManager,
        AccessControlManager $accessControl,
        ValidationServiceInterface $validator,
        AuditLogger $auditLogger,
        array $securityConfig
    ) {
        $this->authManager = $authManager;
        $this->accessControl = $accessControl;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    public function validateSecureOperation(callable $operation, array $context): mixed
    {
        $operationId = uniqid('op_', true);
        $this->auditLogger->logOperationStart($operationId, $context);

        try {
            $this->validatePreOperation($context);
            
            DB::beginTransaction();
            
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;

            $this->validateResult($result);
            $this->validatePostOperation($context);

            DB::commit();

            $this->auditLogger->logOperationSuccess($operationId, [
                'execution_time' => $executionTime,
                'result_hash' => hash('sha256', serialize($result))
            ]);

            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            
            $this->auditLogger->logOperationFailure($operationId, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new SecurityException(
                'Security operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validatePreOperation(array $context): void 
    {
        // Validate authentication
        if (!$this->authManager->validateAuthentication()) {
            throw new SecurityException('Invalid authentication state');
        }

        // Validate authorization
        if (!$this->accessControl->checkPermission($context['permission'] ?? null)) {
            throw new SecurityException('Insufficient permissions');
        }

        // Validate input data
        if (!$this->validator->validateData($context['data'] ?? [], $context['rules'] ?? [])) {
            throw new ValidationException('Input validation failed');
        }

        // Check security constraints
        if (!$this->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function validatePostOperation(array $context): void 
    {
        // Verify data integrity
        if (!$this->verifyDataIntegrity($context)) {
            throw new SecurityException('Data integrity check failed');
        }

        // Check security state
        if (!$this->verifySecurityState()) {
            throw new SecurityException('Invalid security state detected');
        }
    }

    private function checkSecurityConstraints(array $context): bool 
    {
        // Rate limiting
        if (!$this->accessControl->checkRateLimit($context['route'] ?? '')) {
            return false;
        }

        // IP restrictions
        if (!$this->accessControl->validateIpAddress($context['ip'] ?? null)) {
            return false;
        }

        // Session validation
        if (!$this->authManager->validateSession()) {
            return false;
        }

        return true;
    }

    private function verifyDataIntegrity(array $context): bool 
    {
        return $this->validator->verifyIntegrity($context['data'] ?? []);
    }

    private function verifySecurityState(): bool 
    {
        return $this->authManager->checkSecurityState() && 
               $this->accessControl->verifySystemState();
    }
}
