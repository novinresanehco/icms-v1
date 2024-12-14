<?php

namespace App\Core\Data;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};

abstract class BaseRepository implements RepositoryInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected array $config;
    protected string $table;
    protected array $relations = [];
    protected array $searchable = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->config = $config;
    }

    public function find(int $id, array $context = []): ?DataEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeFindOperation($id),
            $this->buildOperationContext('find', $context, $id)
        );
    }

    public function create(array $data, array $context = []): DataEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreateOperation($data),
            $this->buildOperationContext('create', $context)
        );
    }

    public function update(int $id, array $data, array $context = []): DataEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdateOperation($id, $data),
            $this->buildOperationContext('update', $context, $id)
        );
    }

    public function delete(int $id, array $context = []): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDeleteOperation($id),
            $this->buildOperationContext('delete', $context, $id)
        );
    }

    public function search(array $criteria, array $context = []): DataCollection
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeSearchOperation($criteria),
            $this->buildOperationContext('search', $context)
        );
    }

    protected function executeFindOperation(int $id): ?DataEntity
    {
        $cacheKey = $this->getCacheKey('find', $id);
        
        return Cache::remember(
            $cacheKey,
            $this->config['cache_ttl'],
            fn() => $this->findById($id)
        );
    }

    protected function executeCreateOperation(array $data): DataEntity
    {
        $validated = $this->validateData($data);
        
        DB::beginTransaction();
        try {
            $entity = $this->insertEntity($validated);
            $this->processRelations($entity->id, $validated);
            $this->processMetadata($entity->id, $validated);
            
            DB::commit();
            $this->invalidateCache();
            
            return $entity;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryOperationException('Create operation failed', 0, $e);
        }
    }

    protected function executeUpdateOperation(int $id, array $data): DataEntity
    {
        $validated = $this->validateData($data);
        $existing = $this->findById($id);

        if (!$existing) {
            throw new EntityNotFoundException("Entity {$id} not found");
        }

        DB::beginTransaction();
        try {
            $this->createEntityVersion($existing);
            $entity = $this->updateEntity($id, $validated);
            $this->updateRelations($id, $validated);
            $this->updateMetadata($id, $validated);
            
            DB::commit();
            $this->invalidateCache($id);
            
            return $entity;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryOperationException('Update operation failed', 0, $e);
        }
    }

    protected function executeDeleteOperation(int $id): bool
    {
        $existing = $this->findById($id);

        if (!$existing) {
            throw new EntityNotFoundException("Entity {$id} not found");
        }

        DB::beginTransaction();
        try {
            $this->createEntityVersion($existing);
            $this->deleteRelations($id);
            $this->deleteMetadata($id);
            $this->deleteEntity($id);
            
            DB::commit();
            $this->invalidateCache($id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryOperationException('Delete operation failed', 0, $e);
        }
    }

    protected function executeSearchOperation(array $criteria): DataCollection
    {
        $validated = $this->validateSearchCriteria($criteria);
        $cacheKey = $this->getCacheKey('search', $validated);
        
        return Cache::remember(
            $cacheKey,
            $this->config['cache_ttl'],
            fn() => $this->searchEntities($validated)
        );
    }

    protected function validateData(array $data): array
    {
        return $this->validator->validateEntity($data, $this->getValidationRules());
    }

    protected function validateSearchCriteria(array $criteria): array
    {
        return $this->validator->validateSearchCriteria($criteria, $this->searchable);
    }

    protected function findById(int $id): ?DataEntity
    {
        $query = DB::table($this->table)->where('id', $id);
        
        foreach ($this->relations as $relation) {
            $query->with($relation);
        }
        
        return $query->first();
    }

    protected function insertEntity(array $data): DataEntity
    {
        $id = DB::table($this->table)->insertGetId($this->prepareData($data));
        return $this->findById($id);
    }

    protected function updateEntity(int $id, array $data): DataEntity
    {
        DB::table($this->table)
            ->where('id', $id)
            ->update($this->prepareData($data));
            
        return $this->findById($id);
    }

    protected function deleteEntity(int $id): void
    {
        DB::table($this->table)->where('id', $id)->delete();
    }

    protected function searchEntities(array $criteria): DataCollection
    {
        $query = DB::table($this->table);
        
        foreach ($criteria as $field => $value) {
            if (in_array($field, $this->searchable)) {
                $query->where($field, $value);
            }
        }
        
        return new DataCollection($query->get());
    }

    protected function invalidateCache(?int $id = null): void
    {
        if ($id) {
            Cache::forget($this->getCacheKey('find', $id));
        }
        Cache::tags([$this->table])->flush();
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->table,
            $operation,
            md5(serialize($params))
        );
    }

    abstract protected function getValidationRules(): array;
    abstract protected function prepareData(array $data): array;
    abstract protected function processRelations(int $id, array $data): void;
    abstract protected function processMetadata(int $id, array $data): void;
}

class RepositoryOperationException extends \RuntimeException {}
class EntityNotFoundException extends \RuntimeException {}
