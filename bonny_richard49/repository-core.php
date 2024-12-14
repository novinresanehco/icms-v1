<?php

namespace App\Core\Repository;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationSystem;
use App\Core\Events\EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationSystem $validator;
    protected EventDispatcher $events;
    protected array $config;

    public function __construct(
        Model $model,
        SecurityManager $security,
        CacheManager $cache,
        ValidationSystem $validator,
        EventDispatcher $events,
        array $config
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->events = $events;
        $this->config = $config;
    }

    public function find(int $id): ?Model
    {
        $this->validateAccess('read');

        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            $this->config['cache_ttl'],
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data): Model
    {
        $this->validateAccess('create');
        $this->validateData($data, $this->getValidationRules('create'));

        DB::beginTransaction();

        try {
            $model = $this->model->create($data);
            
            $this->processRelations($model, $data);
            $this->updateMetadata($model, 'create');
            
            $this->events->dispatch("model.created", $model);
            
            DB::commit();
            
            $this->clearModelCache();
            
            return $model;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleRepositoryError($e, 'create', $data);
            throw $e;
        }
    }

    public function update(int $id, array $data): Model
    {
        $this->validateAccess('update');
        $this->validateData($data, $this->getValidationRules('update'));

        DB::beginTransaction();

        try {
            $model = $this->model->findOrFail($id);
            
            $this->validateOwnership($model);
            $this->validateVersion($model, $data);
            
            $model->update($data);
            
            $this->processRelations($model, $data);
            $this->updateMetadata($model, 'update');
            
            $this->events->dispatch("model.updated", $model);
            
            DB::commit();
            
            $this->clearModelCache($id);
            
            return $model;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleRepositoryError($e, 'update', $data);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        $this->validateAccess('delete');

        DB::beginTransaction();

        try {
            $model = $this->model->findOrFail($id);
            
            $this->validateOwnership($model);
            $this->cleanupRelations($model);
            
            if ($this->config['soft_delete']) {
                $model->delete();
            } else {
                $model->forceDelete();
            }
            
            $this->events->dispatch("model.deleted", $model);
            
            DB::commit();
            
            $this->clearModelCache($id);
            
            return true;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleRepositoryError($e, 'delete', ['id' => $id]);
            throw $e;
        }
    }

    public function restore(int $id): Model
    {
        $this->validateAccess('restore');

        DB::beginTransaction();

        try {
            $model = $this->model->withTrashed()->findOrFail($id);
            
            $this->validateOwnership($model);
            
            $model->restore();
            $this->updateMetadata($model, 'restore');
            
            $this->events->dispatch("model.restored", $model);
            
            DB::commit();
            
            $this->clearModelCache($id);
            
            return $model;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleRepositoryError($e, 'restore', ['id' => $id]);
            throw $e;
        }
    }

    protected function validateAccess(string $operation): void
    {
        $this->security->validateOperation(
            "repository.{$this->model->getTable()}.{$operation}",
            ['model' => $this->model->getTable()]
        );
    }

    protected function validateOwnership(Model $model): void
    {
        if (!$this->config['ownership_check']) {
            return;
        }

        $user = $this->security->getCurrentUser();
        if (!$user->can('manage', $model)) {
            throw new UnauthorizedException(
                'User does not have ownership of this resource'
            );
        }
    }

    protected function validateVersion(Model $model, array $data): void
    {
        if (!$this->config['version_check']) {
            return;
        }

        if (!isset($data['version']) || 
            $data['version'] !== $model->version) {
            throw new VersionMismatchException(
                'Version mismatch detected'
            );
        }
    }

    protected function processRelations(Model $model, array $data): void
    {
        foreach ($this->config['relations'] as $relation => $config) {
            if (isset($data[$relation])) {
                $this->handleRelation($model, $relation, $data[$relation]);
            }
        }
    }

    protected function handleRelation(Model $model, string $relation, $data): void
    {
        $config = $this->config['relations'][$relation];
        
        if ($config['type'] === 'hasMany') {
            $model->$relation()->sync($data);
        } elseif ($config['type'] === 'belongsTo') {
            $model->$relation()->associate($data);
        }
    }

    protected function cleanupRelations(Model $model): void
    {
        foreach ($this->config['relations'] as $relation => $config) {
            if ($config['cascade_delete']) {
                $model->$relation()->delete();
            }
        }
    }

    protected function updateMetadata(Model $model, string $operation): void
    {
        $user = $this->security->getCurrentUser();
        
        $model->fill([
            "{$operation}_by" => $user->id,
            "{$operation}_at" => now(),
            'version' => $model->version + 1
        ])->save();
    }

    protected function clearModelCache(int $id = null): void
    {
        $tags = [$this->model->getTable()];
        
        if ($id !== null) {
            $tags[] = "{$this->model->getTable()}:{$id}";
        }
        
        $this->cache->tags($tags)->flush();
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }

    protected function getValidationRules(string $operation): array
    {
        return $this->config['validation_rules'][$operation] ?? [];
    }

    protected function handleRepositoryError(
        \Throwable $e,
        string $operation,
        array $data
    ): void {
        $context = [
            'model' => $this->model->getTable(),
            'operation' => $operation,
            'data' => $data,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        if ($this->isDataIntegrityViolation($e)) {
            $this->security->triggerAlert('data_integrity_violation', $context);
        }

        $this->events->dispatch('repository.error', $context);
    }

    protected function isDataIntegrityViolation(\Throwable $e): bool
    {
        return $e instanceof \PDOException && 
            in_array($e->getCode(), [23000, 23505]);
    }
}
