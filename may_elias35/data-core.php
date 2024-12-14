<?php

namespace App\Core\Data;

class DataManager implements DataManagerInterface
{
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $logger;

    public function __construct(
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $logger
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->logger = $logger;
    }

    public function store(array $data): DataResult
    {
        DB::beginTransaction();
        
        try {
            $validatedData = $this->validator->validate($data);
            $encryptedData = $this->encryption->encrypt($validatedData);
            
            $result = $this->repository->store($encryptedData);
            $this->cache->invalidate($this->getCacheKey($result->getId()));
            
            $this->logger->logDataOperation('store', $result->getId());
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('store_failure', $e);
            throw new DataOperationException('Store operation failed', 0, $e);
        }
    }

    public function retrieve(string $id): DataResult
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            config('cache.ttl'),
            function() use ($id) {
                $encryptedData = $this->repository->find($id);
                if (!$encryptedData) {
                    throw new DataNotFoundException('Data not found');
                }
                
                $decryptedData = $this->encryption->decrypt($encryptedData);
                $this->logger->logDataAccess('retrieve', $id);
                
                return new DataResult($decryptedData);
            }
        );
    }

    public function update(string $id, array $data): DataResult
    {
        DB::beginTransaction();
        
        try {
            $validatedData = $this->validator->validate($data);
            $encryptedData = $this->encryption->encrypt($validatedData);
            
            $result = $this->repository->update($id, $encryptedData);
            $this->cache->invalidate($this->getCacheKey($id));
            
            $this->logger->logDataOperation('update', $id);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('update_failure', $e);
            throw new DataOperationException('Update operation failed', 0, $e);
        }
    }

    public function delete(string $id): bool
    {
        DB::beginTransaction();
        
        try {
            $result = $this->repository->delete($id);
            $this->cache->invalidate($this->getCacheKey($id));
            
            $this->logger->logDataOperation('delete', $id);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('delete_failure', $e);
            throw new DataOperationException('Delete operation failed', 0, $e);
        }
    }

    public function validate(array $data, array $rules): ValidationResult
    {
        try {
            $validatedData = $this->validator->validateWithRules($data, $rules);
            return new ValidationResult(true, $validatedData);
            
        } catch (ValidationException $e) {
            $this->logger->logValidationFailure($e->getErrors());
            return new ValidationResult(false, [], $e->getErrors());
        }
    }

    public function search(array $criteria): Collection
    {
        $cacheKey = $this->getCacheKey('search', serialize($criteria));
        
        return $this->cache->remember(
            $cacheKey,
            config('cache.ttl'),
            function() use ($criteria) {
                $results = $this->repository->search($criteria);
                $this->logger->logDataAccess('search', $criteria);
                return $results;
            }
        );
    }

    private function getCacheKey(string $operation, string ...$params): string
    {
        return sprintf(
            'data:%s:%s',
            $operation,
            implode(':', $params)
        );
    }

    public function processBatch(array $operations): array
    {
        DB::beginTransaction();
        
        try {
            $results = [];
            
            foreach ($operations as $operation) {
                $results[] = match ($operation['type']) {
                    'store' => $this->store($operation['data']),
                    'update' => $this->update($operation['id'], $operation['data']),
                    'delete' => $this->delete($operation['id']),
                    default => throw new InvalidOperationException('Invalid operation type')
                };
            }
            
            DB::commit();
            return $results;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('batch_failure', $e);
            throw new DataOperationException('Batch operation failed', 0, $e);
        }
    }
}
