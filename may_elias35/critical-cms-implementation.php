<?php

namespace App\Core\Framework;

/**
 * Core security manager for critical CMS operations
 */
class SecurityManager
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditLogger $logger;
    
    public function validateOperation(Operation $operation): bool
    {
        // Validate all inputs
        $this->validator->validateRequest($operation->getInput());
        
        // Verify permissions
        if (!$this->validateAccess($operation)) {
            $this->logger->logUnauthorizedAccess($operation);
            throw new SecurityException('Access denied');
        }

        // Check integrity
        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            $this->logger->logIntegrityFailure($operation);
            throw new SecurityException('Integrity check failed');
        }

        return true;
    }

    public function executeSecure(callable $operation): mixed
    {
        DB::beginTransaction();
        
        try {
            $result = $operation();
            
            // Verify result integrity
            if (!$this->validateResult($result)) {
                throw new SecurityException('Invalid operation result');
            }
            
            DB::commit();
            $this->logger->logSuccess($operation);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailure($e);
            throw $e;
        }
    }
}

/**
 * Base repository with security and caching
 */
abstract class BaseRepository
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    
    protected function executeSecure(callable $operation) 
    {
        return $this->security->executeSecure($operation);
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember("model.$id", function() use ($id) {
            return $this->executeSecure(fn() => $this->model->find($id));
        });
    }

    public function store(array $data): Model
    {
        return $this->executeSecure(function() use ($data) {
            $model = $this->model->create($this->validate($data));
            $this->cache->forget("model.{$model->id}");
            return $model;
        });
    }
}

/**
 * Core CMS service layer
 */
abstract class BaseService
{
    protected BaseRepository $repository;
    protected ValidationService $validator;
    protected SecurityManager $security;

    public function execute(Operation $operation): Result
    {
        // Validate operation
        $this->security->validateOperation($operation);
        
        // Execute within transaction
        return $this->security->executeSecure(function() use ($operation) {
            return $this->processOperation($operation);
        });
    }

    abstract protected function processOperation(Operation $operation): Result;
    abstract protected function validateOperation(Operation $operation): void;
}
