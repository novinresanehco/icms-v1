<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Core\Contracts\RepositoryInterface;
use App\Core\Exceptions\RepositoryException;

abstract class BaseRepository implements RepositoryInterface
{
    /**
     * @var Model
     */
    protected Model $model;

    /**
     * @var array
     */
    protected array $criteria = [];

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
     * Get all records with optional criteria
     *
     * @param array $columns
     * @param array $relations
     * @return Collection
     */
    public function all(array $columns = ['*'], array $relations = []): Collection
    {
        return $this->model->with($relations)->get($columns);
    }

    /**
     * Get paginated records
     *
     * @param int $perPage
     * @param array $columns
     * @param array $relations
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator
    {
        return $this->model->with($relations)->paginate($perPage, $columns);
    }

    /**
     * Create new record
     *
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model
    {
        try {
            return $this->model->create($attributes);
        } catch (\Exception $e) {
            throw new RepositoryException('Error creating record: ' . $e->getMessage());
        }
    }

    /**
     * Update existing record
     *
     * @param int $id
     * @param array $attributes
     * @return Model
     */
    public function update(int $id, array $attributes): Model
    {
        try {
            $record = $this->find($id);
            $record->update($attributes);
            return $record;
        } catch (\Exception $e) {
            throw new RepositoryException('Error updating record: ' . $e->getMessage());
        }
    }

    /**
     * Delete a record
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        try {
            $record = $this->find($id);
            return $record->delete();
        } catch (\Exception $e) {
            throw new RepositoryException('Error deleting record: ' . $e->getMessage());
        }
    }

    /**
     * Find record by ID
     *
     * @param int $id
     * @param array $columns
     * @param array $relations
     * @param array $appends
     * @return Model|null
     */
    public function find(
        int $id,
        array $columns = ['*'],
        array $relations = [],
        array $appends = []
    ): ?Model {
        return $this->model
            ->select($columns)
            ->with($relations)
            ->findOrFail($id)
            ->append($appends);
    }

    /**
     * Find record by specific criteria
     *
     * @param array $criteria
     * @param array $columns
     * @param array $relations
     * @return Model|null
     */
    public function findBy(array $criteria, array $columns = ['*'], array $relations = []): ?Model
    {
        return $this->model
            ->select($columns)
            ->with($relations)
            ->where($criteria)
            ->first();
    }

    /**
     * Find records matching criteria
     *
     * @param array $criteria
     * @param array $columns
     * @param array $relations
     * @return Collection
     */
    public function findAllBy(array $criteria, array $columns = ['*'], array $relations = []): Collection
    {
        return $this->model
            ->select($columns)
            ->with($relations)
            ->where($criteria)
            ->get();
    }

    /**
     * Apply multiple criteria to query
     *
     * @param array $criteria
     * @return self
     */
    protected function applyCriteria(array $criteria): self
    {
        foreach ($criteria as $criterion) {
            $this->model = $criterion->apply($this->model);
        }
        return $this;
    }
}
