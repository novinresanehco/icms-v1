<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Core\Repository\Contracts\RepositoryInterface;
use App\Core\Repository\Exceptions\RepositoryException;
use App\Core\Repository\Traits\HasCache;
use App\Core\Repository\Traits\HasCriteria;

abstract class BaseRepository implements RepositoryInterface
{
    use HasCache, HasCriteria;

    /**
     * @var Model
     */
    protected Model $model;

    /**
     * BaseRepository constructor.
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Find a resource by id
     *
     * @param int $id
     * @return Model|null
     */
    public function find(int $id): ?Model
    {
        $this->applyCriteria();

        return $this->cacheResult(
            "find_{$id}",
            fn() => $this->model->find($id)
        );
    }

    /**
     * Find a resource by specific field
     *
     * @param string $field
     * @param mixed $value
     * @return Model|null
     */
    public function findBy(string $field, mixed $value): ?Model
    {
        $this->applyCriteria();

        return $this->cacheResult(
            "find_by_{$field}_{$value}",
            fn() => $this->model->where($field, $value)->first()
        );
    }

    /**
     * Get all resources
     *
     * @param array $columns
     * @return Collection
     */
    public function all(array $columns = ['*']): Collection
    {
        $this->applyCriteria();

        return $this->cacheResult(
            'all_' . implode('_', $columns),
            fn() => $this->model->all($columns)
        );
    }

    /**
     * Get paginated resources
     *
     * @param int $perPage
     * @param array $columns
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        $this->applyCriteria();

        return $this->cacheResult(
            "paginate_{$perPage}_" . implode('_', $columns),
            fn() => $this->model->paginate($perPage, $columns)
        );
    }

    /**
     * Create a new resource
     *
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model
    {
        $this->clearCache();
        
        try {
            return $this->model->create($attributes);
        } catch (\Exception $e) {
            throw new RepositoryException("Error creating resource: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Update a resource
     *
     * @param int $id
     * @param array $attributes
     * @return Model
     */
    public function update(int $id, array $attributes): Model
    {
        $this->clearCache();

        try {
            $resource = $this->find($id);
            
            if (!$resource) {
                throw new RepositoryException("Resource not found with ID: {$id}");
            }

            $resource->update($attributes);
            return $resource;
        } catch (\Exception $e) {
            throw new RepositoryException("Error updating resource: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delete a resource
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $this->clearCache();

        try {
            $resource = $this->find($id);
            
            if (!$resource) {
                throw new RepositoryException("Resource not found with ID: {$id}");
            }

            return $resource->delete();
        } catch (\Exception $e) {
            throw new RepositoryException("Error deleting resource: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get resources by condition
     *
     * @param string $field
     * @param mixed $value
     * @param array $columns
     * @return Collection
     */
    public function getWhere(string $field, mixed $value, array $columns = ['*']): Collection
    {
        $this->applyCriteria();

        return $this->cacheResult(
            "where_{$field}_{$value}_" . implode('_', $columns),
            fn() => $this->model->where($field, $value)->get($columns)
        );
    }

    /**
     * Begin a new database transaction
     *
     * @return void
     */
    protected function beginTransaction(): void
    {
        $this->model->getConnection()->beginTransaction();
    }

    /**
     * Commit the active database transaction
     *
     * @return void
     */
    protected function commit(): void
    {
        $this->model->getConnection()->commit();
    }

    /**
     * Rollback the active database transaction
     *
     * @return void
     */
    protected function rollback(): void
    {
        $this->model->getConnection()->rollBack();
    }
}
