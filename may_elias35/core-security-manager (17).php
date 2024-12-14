<?php

namespace App\Core\Security;

use App\Core\Security\Encryption\EncryptionService;
use App\Core\Security\Auth\AuthenticationService;
use App\Core\Security\Validation\ValidationService;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Audit\AuditLogger;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private EncryptionService $encryption;
    private AuthenticationService $auth;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private AuditLogger $audit;

    public function __construct(
        EncryptionService $encryption,
        AuthenticationService $auth,
        ValidationService $validator,
        MetricsCollector $metrics,
        AuditLogger $audit
    ) {
        $this->encryption = $encryption;
        $this->auth = $auth;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->audit = $audit;
    }

    public function executeSecureOperation(Operation $operation): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            $this->validateSecurityState();
            $this->validateOperation($operation);
            
            $result = $this->executeProtectedOperation($operation);
            
            $this->verifyResult($result);
            
            DB::commit();
            $this->recordSuccess($operation, $startTime);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        }
    }

    private function validateSecurityState(): void
    {
        if (!$this->encryption->isHealthy()) {
            throw new SecurityException('Encryption service unhealthy');
        }

        if (!$this->auth->isOperational()) {
            throw new SecurityException('Authentication service down');
        }

        if ($this->metrics->hasAnomalies()) {
            throw new SecurityException('Security anomalies detected');
        }
    }

    private function validateOperation(Operation $operation): void
    {
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid operation');
        }

        if (!$this->auth->checkPermissions($operation)) {
            throw new AuthorizationException('Insufficient permissions');
        }

        if (!$this->validateIntegrity($operation)) {
            throw new IntegrityException('Operation integrity check failed');
        }
    }

    private function executeProtectedOperation(Operation $operation): OperationResult
    {
        $context = $this->createSecurityContext();
        
        try {
            return $operation->execute($context);
        } catch (\Exception $e) {
            $this->handleExecutionFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }

        if (!$this->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }
    }

    private function handleFailure(\Exception $e, Operation $operation): void
    {
        $this->audit->logFailure($operation, $e);
        $this->metrics->recordFailure($operation);
        $this->executeFailureProtocols($e);
    }

    private function validateIntegrity(Operation $operation): bool
    {
        return $this->encryption->verifyIntegrity($operation) &&
               $this->validator->verifyStructure($operation);
    }

    private function verifyResultIntegrity(OperationResult $result): bool
    {
        return $this->encryption->verifyResultIntegrity($result) &&
               $this->validator->verifyResultStructure($result);
    }

    private function createSecurityContext(): SecurityContext
    {
        return new SecurityContext(
            $this->encryption,
            $this->auth,
            $this->metrics
        );
    }

    private function recordSuccess(Operation $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->recordSuccess($operation, $duration);
        $this->audit->logSuccess($operation);
    }

    private function handleExecutionFailure(\Exception $e): void
    {
        $this->audit->logExecutionFailure($e);
        $this->metrics->recordExecutionFailure();
    }

    private function executeFailureProtocols(\Exception $e): void
    {
        if ($e instanceof SecurityException) {
            $this->activateSecurityProtocols();
        }

        if ($e instanceof IntegrityException) {
            $this->activateIntegrityProtocols();
        }
    }

    private function activateSecurityProtocols(): void
    {
        $this->auth->lockdown();
        $this->encryption->rotate();
        $this->metrics->alert();
    }

    private function activateIntegrityProtocols(): void
    {
        $this->validator->enforceStrict();
        $this->encryption->verify();
        $this->audit->mark();
    }
}
