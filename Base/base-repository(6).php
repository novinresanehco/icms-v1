<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface RepositoryInterface
{
    public function all(array $columns = ['*']): Collection;
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator;
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function delete(int $id): bool;
    public function find(int $id, array $columns = ['*']): ?Model;
    public function findOrFail(int $id, array $columns = ['*']): Model;
    public function findWhere(array $criteria, array $columns = ['*']): Collection;
    public function findWhereIn(string $column, array $values, array $columns = ['*']): Collection;
    public function first(array $columns = ['*']): ?Model;
    public function with(array $relations): self;
}

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected Builder $query;

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->resetQuery();
    }

    protected function resetQuery(): void
    {
        $this->query = $this->model->newQuery();
    }

    public function all(array $columns = ['*']): Collection
    {
        $result = $this->query->get($columns);
        $this->resetQuery();
        return $result;
    }

    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        $result = $this->query->paginate($perPage, $columns);
        $this->resetQuery();
        return $result;
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->findOrFail($id);
        $model->update($data);
        return $model;
    }

    public function delete(int $id): bool
    {
        return $this->findOrFail($id)->delete();
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->model->find($id, $columns);
    }

    public function findOrFail(int $id, array $columns = ['*']): Model
    {
        return $this->model->findOrFail($id, $columns);
    }

    public function findWhere(array $criteria, array $columns = ['*']): Collection
    {
        foreach ($criteria as $key => $value) {
            $this->query->where($key, $value);
        }
        
        $result = $this->query->get($columns);
        $this->resetQuery();
        return $result;
    }

    public function findWhereIn(string $column, array $values, array $columns = ['*']): Collection
    {
        $result = $this->query->whereIn($column, $values)->get($columns);
        $this->resetQuery();
        return $result;
    }

    public function first(array $columns = ['*']): ?Model
    {
        $result = $this->query->first($columns);
        $this->resetQuery();
        return $result;
    }

    public function with(array $relations): self
    {
        $this->query->with($relations);
        return $this;
    }
}
