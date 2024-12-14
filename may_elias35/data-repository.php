<?php
namespace App\Core\Repository;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\{SecurityManager, ValidationService};
use App\Core\Interfaces\RepositoryInterface;
use App\Core\Exceptions\{DataException, IntegrityException};

abstract class BaseRepository implements RepositoryInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $audit;
    protected Model $model;
    protected array $relationships = [];
    protected int $cacheDuration = 3600;

    public function find(int $id, SecurityContext $context): ?Model
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return Cache::tags($this->getCacheTags())
                ->remember($this->getCacheKey($id), $this->cacheDuration, function() use ($id) {
                    return $this->model->with($this->relationships)->find($id);
                });
        }, $context);
    }

    public function create(array $data, SecurityContext $context): Model
    {
        return $this->security->executeCriticalOperation(function() use ($data, $context) {
            $validated = $this->validator->validateData($data);
            
            return DB::transaction(function() use ($validated, $context) {
                $model = $this->model->create($validated);
                $this->audit->logCreation($model, $context);
                $this->invalidateCache();
                return $model->load($this->relationships);
            });
        }, $context);
    }

    public function update(int $id, array $data, SecurityContext $context): Model
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data, $context) {
            $model = $this->findOrFail($id);
            $validated = $this->validator->validateData($data);
            
            return DB::transaction(function() use ($model, $validated, $context) {
                $model->update($validated);
                $this->audit->logUpdate($model, $context);
                $this->invalidateCache($model->id);
                return $model->fresh($this->relationships);
            });
        }, $context);
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            $model = $this->findOrFail($id);
            
            return DB::transaction(function() use ($model, $context) {
                $deleted = $model->delete();
                if ($deleted) {
                    $this->audit->logDeletion($model, $context);
                    $this->invalidateCache($model->id);
                }
                return $deleted;
            });
        }, $context);
    }

    public function query(array $criteria, SecurityContext $context): Collection
    {
        return $this->security->executeCriticalOperation(function() use ($criteria) {
            $cacheKey = $this->getQueryCacheKey($criteria);
            
            return Cache::tags($this->getCacheTags())
                ->remember($cacheKey, $this->cacheDuration, function() use ($criteria) {
                    return $this->buildQuery($criteria)
                        ->with($this->relationships)
                        ->get();
                });
        }, $context);
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        if (!$model) {
            throw new DataException("Model not found: {$id}");
        }
        return $model;
    }

    protected function buildQuery(array $criteria): Builder
    {
        $query = $this->model->newQuery();
        
        foreach ($criteria as $field => $value) {
            if ($this->isValidCriteria($field, $value)) {
                $query->where($field, $value);
            }
        }
        
        return $query;
    }

    protected function invalidateCache(?int $id = null): void
    {
        if ($id) {
            Cache::tags($this->getCacheTags())->forget($this->getCacheKey($id));
        } else {
            Cache::tags($this->getCacheTags())->flush();
        }
    }

    protected function getCacheKey(int $id): string
    {
        return sprintf('%s:%d', $this->model->getTable(), $id);
    }

    protected function getQueryCacheKey(array $criteria): string
    {
        return sprintf(
            '%s:query:%s',
            $this->model->getTable(),
            md5(serialize($criteria))
        );
    }

    protected function getCacheTags(): array
    {
        return [$this->model->getTable(), 'repository'];
    }

    protected function isValidCriteria(string $field, $value): bool
    {
        return $this->validator->validateField($field, $value);
    }

    protected function verifyDataIntegrity(Model $model): void
    {
        if (!$this->validator->verifyIntegrity($model)) {
            throw new IntegrityException('Data integrity verification failed');
        }
    }
}
