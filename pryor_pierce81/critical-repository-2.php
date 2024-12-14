<?php
namespace App\Core\Repository;

abstract class CriticalRepository {
    protected ValidationEngine $validator;
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected AuditLogger $logger;
    protected Model $model;

    protected function executeQuery(string $operation, callable $query): mixed {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->security->validateAccess($operation);
            $this->validator->validateOperation($operation);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $query();
            $executionTime = microtime(true) - $startTime;
            
            // Validate result
            $this->validator->validateResult($result);
            
            // Log success
            $this->logger->logOperation($operation, [
                'execution_time' => $executionTime,
                'status' => 'success'
            ]);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($operation, $e);
            throw $e;
        }
    }

    protected function handleError(string $operation, \Exception $e): void {
        $this->logger->logError($operation, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($e instanceof QueryException) {
            throw new DatabaseException(
                "Database operation failed: {$e->getMessage()}", 
                0, 
                $e
            );
        }
    }

    protected function validateData(array $data, array $rules): array {
        $validated = $this->validator->validate($data, $rules);
        
        if (!$validated) {
            throw new ValidationException('Invalid data provided');
        }
        
        return $data;
    }

    protected function cacheQuery(string $key, callable $query): mixed {
        return $this->cache->remember($key, function() use ($query) {
            return $this->executeQuery('cache_query', $query);
        });
    }
}

class ContentRepository extends CriticalRepository {
    public function create(array $data): Content {
        return $this->executeQuery('content_create', function() use ($data) {
            $validated = $this->validateData($data, [
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'required|in:draft,published'
            ]);
            
            $content = $this->model->create($validated);
            $this->cache->invalidateTag('content');
            
            return $content;
        });
    }

    public function update(int $id, array $data): Content {
        return $this->executeQuery('content_update', function() use ($id, $data) {
            $content = $this->model->findOrFail($id);
            
            $validated = $this->validateData($data, [
                'title' => 'string|max:255',
                'content' => 'string',
                'status' => 'in:draft,published'
            ]);
            
            $content->update($validated);
            $this->cache->invalidate("content.$id");
            
            return $content->fresh();
        });
    }

    public function delete(int $id): bool {
        return $this->executeQuery('content_delete', function() use ($id) {
            $content = $this->model->findOrFail($id);
            $result = $content->delete();
            
            if ($result) {
                $this->cache->invalidate("content.$id");
                $this->cache->invalidateTag('content');
            }
            
            return $result;
        });
    }

    public function find(int $id): ?Content {
        return $this->cacheQuery("content.$id", function() use ($id) {
            return $this->model->findOrFail($id);
        });
    }

    public function findWithRelations(int $id, array $relations): ?Content {
        return $this->cacheQuery("content.$id.relations", function() use ($id, $relations) {
            return $this->model->with($relations)->findOrFail($id);
        });
    }
}

interface ValidationEngine {
    public function validate(array $data, array $rules): bool;
    public function validateOperation(string $operation): void;
    public function validateResult($result): void;
}

interface SecurityManager {
    public function validateAccess(string $operation): void;
}

interface CacheManager {
    public function remember(string $key, callable $callback): mixed;
    public function invalidate(string $key): void;
    public function invalidateTag(string $tag): void;
}

interface AuditLogger {
    public function logOperation(string $operation, array $context): void;
    public function logError(string $operation, array $context): void;
}

class DatabaseException extends \Exception {}
class ValidationException extends \Exception {}
class CacheException extends \Exception {}
