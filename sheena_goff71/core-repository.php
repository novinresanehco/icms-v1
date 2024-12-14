<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\RepositoryInterface;
use App\Core\Events\DataEvent;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $auditLogger;
    protected CacheManager $cache;
    protected MetricsCollector $metrics;

    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator,
        AuditLogger $auditLogger,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey('find', $id, $relations);

        try {
            return $this->cache->remember($cacheKey, config('cache.ttl'), function() use ($id, $relations) {
                $query = $this->model->newQuery();
                
                if (!empty($relations)) {
                    $query->with($relations);
                }
                
                return $query->find($id);
            });
        } finally {
            $this->metrics->record('repository.find', microtime(true) - $startTime);
        }
    }

    public function create(array $data): Model
    {
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $validated = $this->validator->validate($data, $this->getCreationRules());
            
            $model = $this->model->newInstance($validated);
            $model->save();

            $this->createAuditTrail('created', $model);
            
            event(new DataEvent("{$this->getEventPrefix()}.created", $model));
            
            DB::commit();
            
            $this->invalidateCache();

            return $model;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'create', $data);
            throw $e;
        } finally {
            $this->metrics->record('repository.create', microtime(true) - $startTime);
        }
    }

    public function update(int $id, array $data): Model
    {
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $model = $this->findOrFail($id);
            $validated = $this->validator->validate($data, $this->getUpdateRules($model));

            $model->fill($validated);
            $model->save();

            $this->createAuditTrail('updated', $model);
            
            event(new DataEvent("{$this->getEventPrefix()}.updated", $model));
            
            DB::commit();
            
            $this->invalidateCache($model);

            return $model;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'update', ['id' => $id, 'data' => $data]);
            throw $e;
        } finally {
            $this->metrics->record('repository.update', microtime(true) - $startTime);
        }
    }

    public function delete(int $id, bool $force = false): bool
    {
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $model = $this->findOrFail($id);

            if ($force) {
                $result = $model->forceDelete();
            } else {
                $result = $model->delete();
            }

            $this->createAuditTrail($force ? 'force_deleted' : 'deleted', $model);
            
            event(new DataEvent(
                "{$this->getEventPrefix()}." . ($force ? 'force_deleted' : 'deleted'),
                $model
            ));
            
            DB::commit();
            
            $this->invalidateCache($model);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e, 'delete', ['id' => $id, 'force' => $force]);
            throw $e;
        } finally {
            $this->metrics->record('repository.delete', microtime(true) - $startTime);
        }
    }

    protected function findOrFail(int $id, array $relations = []): Model
    {
        $model = $this->find($id, $relations);

        if (!$model) {
            throw new ModelNotFoundException("Model not found with ID: {$id}");
        }

        return $model;
    }

    protected function createAuditTrail(string $action, Model $model): void
    {
        $this->auditLogger->log("model_{$action}", [
            'model' => get_class($model),
            'id' => $model->id,
            'data' => $model->getDirty()
        ]);
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            md5(serialize($params))
        );
    }

    protected function invalidateCache(?Model $model = null): void
    {
        if ($model) {
            $this->cache->forget($this->getCacheKey('find', $model->id));
        }
        
        $this->cache->tags($this->model->getTable())->flush();
    }

    protected function handleError(\Exception $e, string $operation, array $context): void
    {
        $this->auditLogger->logError('repository_operation_failed', [
            'model' => get_class($this->model),
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('repository.error', [
            'model' => get_class($this->model),
            'operation' => $operation
        ]);
    }

    protected function getEventPrefix(): string
    {
        return strtolower(class_basename($this->model));
    }

    abstract protected function getCreationRules(): array;
    abstract protected function getUpdateRules(Model $model): array;
}
