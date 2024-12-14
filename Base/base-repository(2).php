<?php

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository implements RepositoryInterface
{
    /**
     * @var Model
     */
    protected Model $model;

    /**
     * Get the model instance.
     *
     * @return Model
     */
    abstract protected function getModel(): Model;

    /**
     * BaseRepository constructor.
     */
    public function __construct()
    {
        $this->model = $this->getModel();
    }

    /**
     * {@inheritDoc}
     */
    public function find(int|string $id, array $relations = []): ?Model
    {
        try {
            return $this->model->with($relations)->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findBy(array $criteria, array $relations = []): ?Model
    {
        return $this->model->with($relations)->where($criteria)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function all(array $relations = [], array $orderBy = ['id' => 'asc']): Collection
    {
        $query = $this->model->with($relations);

        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int|string $id, array $data): bool
    {
        return $this->model->findOrFail($id)->update($data);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int|string $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(int $perPage = 15, array $relations = [], array $orderBy = ['id' => 'asc']): LengthAwarePaginator
    {
        $query = $this->model->with($relations);

        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        return $query->paginate($perPage);
    }
}
