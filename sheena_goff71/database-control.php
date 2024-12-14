<?php

namespace App\Core\Database;

class DatabaseControlSystem 
{
    private const VALIDATION_MODE = 'STRICT';
    private QueryValidator $validator;
    private TransactionManager $transaction;
    private SecurityEnforcer $security;

    public function executeQuery(DatabaseOperation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation);
            $this->enforceSecurityProtocols($operation);
            $result = $this->executeValidated($operation);
            $this->validateResult($result);
            
            DB::commit();
            return $result;
            
        } catch (ValidationException $e) {
            DB::rollBack();
            throw new CriticalOperationException("Validation failed", $e);
        } catch (SecurityException $e) {
            DB::rollBack();
            throw new CriticalOperationException("Security violation", $e);
        }
    }

    private function validateOperation(DatabaseOperation $operation): void
    {
        if (!$this->validator->validate($operation)) {
            throw new ValidationException();
        }
    }

    private function enforceSecurityProtocols(DatabaseOperation $operation): void
    {
        $this->security->enforceProtocols($operation);
    }

    private function executeValidated(DatabaseOperation $operation): OperationResult
    {
        return $this->transaction->execute($operation);
    }
}

class TransactionManager
{
    private QueryExecutor $executor;
    private StateValidator $validator;
    
    public function execute(DatabaseOperation $operation): OperationResult
    {
        $this->validateState();
        $result = $this->executor->execute($operation);
        $this->validateResult($result);
        return $result;
    }

    private function validateState(): void
    {
        if (!$this->validator->validateDatabaseState()) {
            throw new StateException();
        }
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new ResultException();
        }
    }
}

class SecurityEnforcer 
{
    private AccessControl $access;
    private IntegrityChecker $integrity;
    
    public function enforceProtocols(DatabaseOperation $operation): void
    {
        $this->validateAccess($operation);
        $this->checkIntegrity($operation);
        $this->enforceConstraints($operation);
    }

    private function validateAccess(DatabaseOperation $operation): void
    {
        if (!$this->access->validate($operation)) {
            throw new AccessException();
        }
    }

    private function checkIntegrity(DatabaseOperation $operation): void
    {
        if (!$this->integrity->check($operation)) {
            throw new IntegrityException();
        }
    }
}
