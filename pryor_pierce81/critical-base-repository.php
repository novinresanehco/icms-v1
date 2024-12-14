<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Core\Services\{CacheManager, ValidationService, AuditLogger};
use App\Core\Exceptions\{RepositoryException, ValidationException};

abstract class CriticalBaseRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected AuditLogger $auditLogger;
    protected array $securityConfig;

    public function __construct(
        Model $model,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $auditLogger,
        array $securityConfig
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    public function find(int $id): ?Model
    {
        return $this->executeSecure(function() use ($id) {
            return $this->cache->remember(
                $this->getCacheKey('find', $id),
                fn() => $this->model->find($id)
            );
        });
    }

    public function create(array $data): Model
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validateData($data);
            
            $result = DB::transaction(function() use ($validated) {
                $model = $this->model->create($validated);
                $this->cache->invalidate($this->getCacheKey('find', $model->id));
                return $model;
            });

            $this->auditLogger->logCreate([
                'model' => get_class($this->model),
                'data' => $validated,
                'result' => $result->id
            ]);

            return $result;
        });
    }

    public function update(int $id, array $data): Model
    {
        return $this->executeSecure(function() use ($id, $data) {
            $validated = $this->validateData($data);
            
            $result = DB::transaction(function() use ($id, $validated) {
                $model = $this->model->findOrFail($id);
                $model->update($validated);
                $this->cache->invalidate($this->getCacheKey('find', $id));
                return $model;
            });

            $this->auditLogger->logUpdate([
                'model' => get_class($this->model),
                'id' => $id,
                'data' => $validated
            ]);

            return $result;
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecure(function() use ($id) {
            $result = DB::transaction(function() use ($id) {
                $model = $this->model->findOrFail($id);
                $deleted = $model->delete();
                $this->cache->invalidate($this->getCacheKey('find', $id));
                return $deleted;
            });

            $this->auditLogger->logDelete([
                'model' => get_class($this->model),
                'id' => $id
            ]);

            return $result;
        });
    }

    protected function executeSecure(callable $operation)
    {
        try {
            $this->validateState();
            return $operation();
        } catch (\Exception $e) {
            $this->handleError($e);
            throw new RepositoryException(
                'Repository operation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    protected function validateData(array $data): array
    {
        $validated = $this->validator->validate($data, $this->getValidationRules());
        
        if (!$validated) {
            throw new ValidationException('Data validation failed');
        }
        
        return $validated;
    }

    protected function validateState(): void
    {
        if (!$this->validator->validateSystemState()) {
            throw new RepositoryException('Invalid system state');
        }
    }

    protected function handleError(\Exception $e): void
    {
        $this->auditLogger->logError([
            'repository' => get_class($this),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    abstract protected function getCacheKey(string $operation, ...$params): string;
    abstract protected function getValidationRules(): array;
}
