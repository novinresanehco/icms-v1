<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Exceptions\RepositoryException;

abstract class BaseRepository implements RepositoryInterface
{
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
     * {@inheritDoc}
     */
    public function all(array $columns = ['*'], array $relations = []): Collection
    {
        try {
            return $this->model->with($relations)->get($columns);
        } catch (QueryException $e) {
            throw new RepositoryException("Error retrieving records: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator
    {
        try {
            return $this->model->with($relations)->paginate($perPage, $columns);
        } catch (QueryException $e) {
            throw new RepositoryException("Error paginating records: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findById(
        int $modelId,
        array $columns = ['*'],
        array $relations = [],
        array $appends = []
    ): ?Model {
        try {
            $query = $this->model->with($relations);

            if (!empty($appends)) {
                $query->append($appends);
            }

            return $query->findOrFail($modelId, $columns);
        } catch (ModelNotFoundException $e) {
            return null;
        } catch (QueryException $e) {
            throw new RepositoryException("Error finding record: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findByField(
        string $field,
        mixed $value,
        array $columns = ['*'],
        array $relations = []
    ): ?Model {
        try {
            return $this->model->with($relations)
                ->where($field, '=', $value)
                ->first($columns);
        } catch (QueryException $e) {
            throw new RepositoryException("Error finding record by field: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findManyByField(
        string $field,
        array $values,
        array $columns = ['*'],
        array $relations = []
    ): Collection {
        try {
            return $this->model->with($relations)
                ->whereIn($field, $values)
                ->get($columns);
        } catch (QueryException $e) {
            throw new RepositoryException("Error finding records by field: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $payload): Model
    {
        try {
            $model = $this->model->create($payload);
            return $model->fresh();
        } catch (QueryException $e) {
            throw new RepositoryException("Error creating record: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $modelId, array $payload): Model
    {
        try {
            $model = $this->findById($modelId);

            if (!$model) {
                throw new ModelNotFoundException("Record not found with ID: {$modelId}");
            }

            $model->update($payload);
            return $model->fresh();
        } catch (QueryException $e) {
            throw new RepositoryException("Error updating record: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(int $modelId): bool
    {
        try {
            $model = $this->findById($modelId);

            if (!$model) {
                throw new ModelNotFoundException("Record not found with ID: {$modelId}");
            }

            return $model->delete();
        } catch (QueryException $e) {
            throw new RepositoryException("Error deleting record: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    /**
     * {@inheritDoc}
     */
    public function commit(): void
    {
        DB::commit();
    }

    /**
     * {@inheritDoc}
     */
    public function rollBack(): void
    {
        DB::rollBack();
    }

    /**
     * Get the model instance.
     *
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }
}
