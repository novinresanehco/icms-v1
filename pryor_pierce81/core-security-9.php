<?php

namespace App\Core\Security;

use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface
};
use App\Core\Security\Services\{
    EncryptionService,
    AccessControlService
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    UnauthorizedException
};
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private EncryptionService $encryption;
    private AuditLoggerInterface $auditLogger;
    private AccessControlService $accessControl;

    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionService $encryption,
        AuditLoggerInterface $auditLogger,
        AccessControlService $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperationContext($context);
            
            // Execute operation with monitoring
            $startTime = microtime(true);
            $result = $this->executeWithProtection($operation, $context);
            $executionTime = microtime(true) - $startTime;
            
            // Validate result
            $this->validateOperationResult($result);
            
            // Log success and commit
            $this->auditLogger->logSuccess($context, $result, $executionTime);
            DB::commit();
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function validateOperationContext(array $context): void
    {
        // Validate authentication
        if (!$this->accessControl->validateAuthentication($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException('Invalid authentication');
        }

        // Check authorization
        if (!$this->accessControl->checkPermissions($context)) {
            $this->auditLogger->logUnauthorizedAction($context);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Validate input data
        if (!$this->validator->validateInput($context['data'] ?? [])) {
            throw new ValidationException('Invalid input data');
        }

        // Additional security checks
        $this->performSecurityChecks($context);
    }

    private function executeWithProtection(callable $operation, array $context): mixed
    {
        return $this->accessControl->executeInSecureContext(function() use ($operation, $context) {
            return $operation($context);
        });
    }

    private function validateOperationResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleSecurityFailure(\Throwable $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'security_context' => $this->accessControl->getCurrentContext(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function performSecurityChecks(array $context): void
    {
        // Rate limiting check
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // IP verification if required
        if (isset($context['ip_verification']) && !$this->accessControl->verifyIpAddress($context['ip'])) {
            throw new SecurityException('IP verification failed');
        }

        // Additional contextual security validations
        if (!$this->accessControl->validateSecurityContext($context)) {
            throw new SecurityException('Security context validation failed');
        }
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'time' => microtime(true),
            'connections' => DB::connection()->getDatabaseName()
        ];
    }
}
