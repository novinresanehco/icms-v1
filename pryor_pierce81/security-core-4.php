<?php

namespace App\Core\Security;

use App\Core\Exception\SecurityException;
use App\Core\Audit\AuditManagerInterface;
use App\Core\Validation\ValidationManagerInterface;
use Psr\Log\LoggerInterface;

class SecurityCore implements SecurityCoreInterface 
{
    private ValidationManagerInterface $validator;
    private AuditManagerInterface $audit;
    private LoggerInterface $logger;
    private array $config;
    
    private const CRITICAL_OPERATIONS = [
        'auth' => true,
        'data' => true,
        'system' => true
    ];

    public function __construct(
        ValidationManagerInterface $validator,
        AuditManagerInterface $audit, 
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateSecureOperation(string $operation, array $context): bool
    {
        $operationId = $this->generateOperationId();
        
        try {
            DB::beginTransaction();

            $this->validateOperationContext($operation, $context);
            $this->validateSecurityConstraints($context);
            $this->validateResourceAccess($context);
            
            $this->audit->logSecurityCheck($operationId, $operation, $context);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($operationId, $operation, $e);
            throw new SecurityException('Security validation failed', 0, $e);
        }
    }

    public function enforceSecurityPolicy(string $policy, array $params): void
    {
        $this->validator->validatePolicy($policy, $params);
        
        if (!$this->isPolicyEnabled($policy)) {
            throw new SecurityException("Security policy not enabled: {$policy}");
        }

        $this->applySecurityPolicy($policy, $params);
        $this->verifyPolicyApplication($policy);
        
        $this->audit->logPolicyEnforcement($policy, $params);
    }

    private function validateOperationContext(string $operation, array $context): void
    {
        if (!isset(self::CRITICAL_OPERATIONS[$operation])) {
            throw new SecurityException('Invalid security operation');
        }

        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->validateSecurityState()) {
            throw new SecurityException('System security state invalid');
        }
    }

    private function validateSecurityConstraints(array $context): void
    {
        foreach ($this->config['security_constraints'] as $constraint) {
            if (!$this->validator->validateConstraint($constraint, $context)) {
                throw new SecurityException("Security constraint violation: {$constraint}");
            }
        }
    }

    private function validateResourceAccess(array $context): void
    {
        if (!isset($context['resource'])) {
            throw new SecurityException('Resource not specified');
        }

        if (!$this->hasResourceAccess($context['resource'], $context)) {
            throw new SecurityException('Resource access denied');
        }
    }

    private function validateSecurityState(): bool
    {
        return $this->checkSystemIntegrity() && 
               $this->verifySecurityModules() && 
               $this->validateProtectionStatus();
    }

    private function handleSecurityFailure(string $operationId, string $operation, \Exception $e): void
    {
        $this->logger->critical('Security failure', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->audit->logSecurityFailure($operationId, $operation, $e);
        $this->executeEmergencyProtocol($operationId, $operation);
    }

    private function getDefaultConfig(): array
    {
        return [
            'security_constraints' => [
                'input_validation',
                'access_control',
                'encryption_check',
                'integrity_validation'
            ],
            'policy_timeout' => 300,
            'max_attempts' => 3,
            'strict_mode' => true
        ];
    }
}
