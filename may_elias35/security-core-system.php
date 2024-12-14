<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;
use App\Core\Validation\ValidationService;
use App\Core\Protection\ProtectionManager;
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private ProtectionManager $protector;
    private array $config;

    public function __construct(
        ValidationService $validator,
        AuditLogger $auditLogger,
        ProtectionManager $protector,
        array $config
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->protector = $protector;
        $this->config = $config;
    }

    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation, $context);
            
            $result = $this->executeWithProtection($operation, $context);
            
            $this->verifyResult($result);
            
            DB::commit();
            
            $this->auditLogger->logSecurityEvent(
                'critical_operation_complete',
                ['operation' => $operation->getType()],
                $context
            );
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed',
                previous: $e
            );
        }
    }

    private function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        if (!$this->protector->checkPermissions($context, $operation)) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        if (!$this->protector->checkRateLimit($context)) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $this->protector->startProtection($operation);
        
        try {
            return $operation->execute();
        } finally {
            $this->protector->endProtection($operation);
        }
    }

    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        $this->auditLogger->logSecurityEvent(
            'critical_operation_failed',
            [
                'operation' => $operation->getType(),
                'error' => $e->getMessage()
            ],
            $context
        );

        $this->protector->handleFailure($operation, $e);
    }
}
