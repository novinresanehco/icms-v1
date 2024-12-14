<?php

namespace App\Core\Repository;

use App\Core\Repository\Contracts\RepositoryInterface;
use App\Core\Repository\Criteria\CriteriaInterface;
use App\Core\Repository\Exceptions\RepositoryException;
use App\Core\Cache\Contracts\CacheManagerInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class AbstractRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManagerInterface $cache;
    protected array $criteria = [];
    protected ?string $cacheKey = null;
    protected ?int $cacheTtl = null;
    protected bool $enableCache = true;

    /**
     * Get the model class name.
     *
     * @return string
     */
    abstract protected function getModelClass(): string;

    public function __construct(CacheManagerInterface $cache)
    {
        $this->cache = $cache;
        $this->makeModel();
    }

    public function find($id): ?Model
    {
        try {
            if ($this->enableCache && $this->cacheKey) {
                return $this->cache->remember(
                    $this->getCacheKey("find.{$id}"),
                    fn() => $this->model->find($id),
                    $this->cacheTtl
                );
            }

            return $this->model->find($id);
        } catch (\Exception $e) {
            Log::error('Repository find error', [
                'model' => get_class($this->model),
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RepositoryException("Error finding model: {$e->getMessage()}", 0, $e);
        }
    }

    public function findBy(array $criteria): ?Model
    {
        try {
            $query = $this->model->newQuery();

            foreach ($criteria as $key => $value) {
                $query->where($key, '=', $value);
            }

            if ($this->enableCache && $this->cacheKey) {
                $cacheKey = $this->getCacheKey('findBy.' . md5(serialize($criteria)));
                return $this->cache->remember(
                    $cacheKey,
                    fn() => $query->first(),
                    $this->cacheTtl
                );
            }

            return $query->first();
        } catch (\Exception $e) {
            Log::error('Repository findBy error', [
                'model' => get_class($this->model),
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            throw new RepositoryException("Error finding model by criteria: {$e->getMessage()}", 0, $e);
        }
    }

    public function all(): Collection
    {
        try {
            $query = $this->model->newQuery();

            foreach ($this->criteria as $criteria) {
                $query = $criteria->apply($query);
            }

            if ($this->enableCache && $this->cacheKey) {
                return $this->cache->remember(
                    $this->getCacheKey('all'),
                    fn() => $query->get(),
                    $this->cacheTtl
                );
            }

            return $query->get();
        } catch (\Exception $e) {
            Log::error('Repository all error', [
                'model' => get_class($this->model),
                'error' => $e->getMessage()
            ]);
            throw new RepositoryException("Error retrieving all models: {$e->getMessage()}", 0, $e);
        }
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();

        try {
            $model = $this->model->create($data);

            if ($this->enableCache && $this->cacheKey) {
                $this->cache->tags($this->cacheKey)->flush();
            }

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repository create error', [
                'model' => get_class($this->model),
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw new RepositoryException("Error creating model: {$e->getMessage()}", 0, $e);
        }
    }

    public function update($id, array $data): Model
    {
        DB::beginTransaction();

        try {
            $model = $this->find($id);

            if (!$model) {
                throw new ModelNotFoundException("Model not found with ID {$id}");
            }

            $model->update($data);

            if ($this->enableCache && $this->cacheKey) {
                $this->cache->tags($this->cacheKey)->flush();
            }

            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repository update error', [
                'model' => get_class($this->model),
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw new RepositoryException("Error updating model: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete($id): bool
    {
        DB::beginTransaction();

        try {
            $model = $this->find($id);

            if (!$model) {
                throw new ModelNotFoundException("Model not found with ID {$id}");
            }

            $deleted = $model->delete();

            if ($this->enableCache && $this->cacheKey) {
                $this->cache->tags($this->cacheKey)->flush();
            }

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repository delete error', [
                'model' => get_class($this->model),
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RepositoryException("Error deleting model: {$e->getMessage()}", 0, $e);
        }
    }

    public function withCriteria(CriteriaInterface ...$criteria): self
    {
        $this->criteria = array_merge($this->criteria, $criteria);
        return $this;
    }

    public function beginTransaction(): self
    {
        DB::beginTransaction();
        return $this;
    }

    public function commit(): self
    {
        DB::commit();
        return $this;
    }

    public function rollback(): self
    {
        DB::rollBack();
        return $this;
    }

    public function cache(string $key, ?int $ttl = null): self
    {
        $this->cacheKey = $key;
        $this->cacheTtl = $ttl;
        return $this;
    }

    protected function makeModel(): void
    {
        $model = app($this->getModelClass());

        if (!$model instanceof Model) {
            throw new RepositoryException(
                "Class {$this->getModelClass()} must be an instance of Illuminate\\Database\\Eloquent\\Model"
            );
        }

        $this->model = $model;
    }

    protected function getCacheKey(string $suffix): string
    {
        return sprintf(
            '%s.%s.%s',
            $this->cacheKey,
            strtolower(class_basename($this->model)),
            $suffix
        );
    }
}
