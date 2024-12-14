<?php

namespace App\Core\Services;

// Critical Service Layer - Core Implementation
abstract class CriticalService
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $logger;
    protected TransactionManager $transaction;
    protected CacheManager $cache;

    protected function executeProtected(callable $operation): mixed
    {
        $this->transaction->begin();
        
        try {
            // Pre-execution security validation
            $context = $this->security->createContext();
            $this->security->validateContext($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Validate result
            $this->validator->validateResult($result);
            
            $this->transaction->commit();
            return $result;
            
        } catch (\Exception $e) {
            $this->transaction->rollback();
            $this->handleFailure($e);
            throw $e;
        }
    }

    protected function cacheResult(string $key, mixed $value): void
    {
        $this->cache->store($key, $value, ['security' => 'critical']);
    }

    protected function validateInput(array $data, array $rules): array
    {
        return $this->validator->validate($data, $rules);
    }
}

// Content Management Service
final class ContentManagementService extends CriticalService 
{
    private ContentRepository $repository;
    private MediaManager $media;

    public function create(array $content): Content
    {
        return $this->executeProtected(function() use ($content) {
            $validated = $this->validateInput($content, [
                'title' => 'required|string|max:200',
                'body' => 'required|string',
                'status' => 'required|in:draft,published'
            ]);
            
            $content = $this->repository->create($validated);
            $this->logger->logCreation($content);
            
            return $content;
        });
    }

    public function publish(int $id): void
    {
        $this->executeProtected(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            $content->publish();
            $this->repository->save($content);
            $this->cache->invalidate(['content', $id]);
        });
    }
}

// Critical Repository Pattern
abstract class CriticalRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    
    protected function executeQuery(callable $query)
    {
        try {
            return $query();
        } catch (\Exception $e) {
            $this->logger->logQueryFailure($e);
            throw new RepositoryException('Query execution failed', 0, $e);
        }
    }

    protected function cacheQuery(string $key, callable $query)
    {
        return $this->cache->remember($key, function() use ($query) {
            return $this->executeQuery($query);
        });
    }
}

// Media Management Service
final class MediaManagementService extends CriticalService
{
    private StorageManager $storage;
    private MediaProcessor $processor;

    public function store(UploadedFile $file): Media
    {
        return $this->executeProtected(function() use ($file) {
            $this->validator->validateFile($file);
            
            $path = $this->storage->store($file, 'secure');
            $media = $this->processor->process($path);
            
            return $media;
        });
    }
}

// Critical Cache Management
final class CriticalCacheManager
{
    private CacheStore $store;
    private EncryptionService $encryption;

    public function store(string $key, mixed $value, array $tags = []): void
    {
        $encrypted = $this->encryption->encrypt(serialize($value));
        $this->store->put($key, $encrypted, $tags);
    }

    public function get(string $key): mixed
    {
        $encrypted = $this->store->get($key);
        return $encrypted ? unserialize(
            $this->encryption->decrypt($encrypted)
        ) : null;
    }
}

// Security-Aware Content Repository
final class ContentRepository extends CriticalRepository
{
    public function findWithSecurity(int $id): ?Content
    {
        return $this->cacheQuery("content.$id", function() use ($id) {
            return $this->model
                ->with(['security', 'audit'])
                ->findOrFail($id);
        });
    }

    public function create(array $data): Content
    {
        return $this->executeQuery(function() use ($data) {
            $content = $this->model->create($data);
            $this->createAuditTrail($content);
            return $content;
        });
    }
}

// Critical Transaction Manager
final class TransactionManager
{
    private bool $inTransaction = false;

    public function begin(): void
    {
        if ($this->inTransaction) {
            throw new TransactionException('Transaction already in progress');
        }
        DB::beginTransaction();
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new TransactionException('No transaction in progress');
        }
        DB::commit();
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        if (!$this->inTransaction) {
            throw new TransactionException('No transaction to rollback');
        }
        DB::rollBack();
        $this->inTransaction = false;
    }
}
