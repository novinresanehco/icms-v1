<?php

namespace App\Core\Security;

class SecurityEnforcer implements SecurityEnforcerInterface
{
    private ValidationChain $validator;
    private EncryptionService $encryption;
    private AuditLogger $logger;

    public function enforceOperation(Operation $operation): Result
    {
        return DB::transaction(function() use ($operation) {
            $context = $this->createSecurityContext($operation);
            
            $this->validatePreExecution($context);
            
            $result = $this->executeSecure($operation, $context);
            
            $this->validateResult($result, $context);
            
            return $result;
        });
    }

    private function validatePreExecution(Context $context): void
    {
        $this->validator->validateChain([
            new AccessValidator($context),
            new IntegrityValidator($context),
            new ResourceValidator($context),
            new RateLimitValidator($context)
        ]);
    }

    private function executeSecure(Operation $operation, Context $context): Result
    {
        $this->logger->logExecutionStart($context);
        
        try {
            $result = $operation->execute();
            $this->logger->logExecutionSuccess($context);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->logExecutionFailure($e, $context);
            throw $e; 
        }
    }

    private function validateResult(Result $result, Context $context): void
    {
        $this->validator->validateChain([
            new ResultIntegrityValidator($result),
            new SecurityCompliance($result),
            new BusinessRules($result)
        ]);
    }
}