<?php

namespace App\Core\Security;

use App\Core\Validation\ValidationManagerInterface;
use App\Core\Audit\AuditManagerInterface;
use App\Core\Exception\SecurityException;
use Psr\Log\LoggerInterface;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationManagerInterface $validator;
    private AuditManagerInterface $audit;
    private LoggerInterface $logger;
    private array $config;

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

    public function validateRequest(Request $request): bool
    {
        $validationId = $this->generateValidationId();
        
        try {
            DB::beginTransaction();

            $this->validateRequestStructure($request);
            $this->validateAuthentication($request);
            $this->validateAuthorization($request);
            $this->validateResourceAccess($request);
            
            $this->audit->logValidation($validationId, $request);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($validationId, $e);
            throw new SecurityException('Request validation failed', 0, $e);
        }
    }

    public function enforcePolicy(string $policy, array $context): void
    {
        if (!$this->validator->validatePolicy($policy, $context)) {
            throw new SecurityException("Policy validation failed: {$policy}");
        }

        try {
            $this->applySecurityPolicy($policy, $context);
            $this->verifyPolicyApplication($policy);
            $this->audit->logPolicyEnforcement($policy, $context);
        } catch (\Exception $e) {
            throw new SecurityException("Policy enforcement failed: {$policy}", 0, $e);
        }
    }

    public function validateOperation(string $operation, array $context): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();
            
            $this->validateOperationContext($operation, $context);
            $this->validateSecurityState();
            $this->validateResourceState($context);
            
            $this->audit->logOperation($operationId, $operation, $context);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($operationId, $operation, $e);
            throw new SecurityException('Operation validation failed', 0, $e);
        }
    }

    private function validateRequestStructure(Request $request): void
    {
        if (!$this->validator->validateStructure($request, $this->config['request_rules'])) {
            throw new SecurityException('Invalid request structure');
        }
    }

    private function validateAuthentication(Request $request): void
    {
        if (!$this->validator->validateAuthentication($request)) {
            throw new SecurityException('Authentication validation failed');
        }
    }

    private function validateAuthorization(Request $request): void
    {
        if (!$this->validator->validateAuthorization($request)) {
            throw new SecurityException('Authorization validation failed');
        }
    }

    private function validateResourceAccess(Request $request): void
    {
        if (!$this->validator->validateResourceAccess($request)) {
            throw new SecurityException('Resource access validation failed');
        }
    }

    private function validateSecurityState(): void
    {
        if (!$this->checkSystemSecurity()) {
            throw new SecurityException('System security check failed');
        }

        if (!$this->verifyProtectionStatus()) {
            throw new SecurityException('Protection status verification failed');
        }
    }

    private function handleSecurityFailure(string $id, \Exception $e): void
    {
        $this->logger->critical('Security failure', [
            'id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->audit->logFailure($id, $e);
        $this->executeEmergencyProtocol($id);
    }

    private function getDefaultConfig(): array
    {
        return [
            'request_rules' => [
                'authentication' => true,
                'authorization' => true,
                'resource_validation' => true,
                'input_validation' => true
            ],
            'validation_timeout' => 30,
            'max_attempts' => 3,
            'strict_mode' => true
        ];
    }
}
