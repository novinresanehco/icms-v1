<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use App\Core\Cache\CacheableRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class BaseRepository
{
    use CacheableRepository;

    protected Model $model;

    public function __construct()
    {
        $this->initializeCache();
    }

    public function find(int $id): ?Model
    {
        return $this->executeWithCache(__FUNCTION__, [$id], function () use ($id) {
            return $this->model->find($id);
        });
    }

    public function findWhere(array $criteria): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$criteria], function () use ($criteria) {
            return $this->model->where($criteria)->get();
        });
    }

    public function create(array $data): Model
    {
        $model = $this->model->create($data);
        $this->clearCache();
        return $model;
    }

    public function update(int $id, array $data): bool
    {
        $result = $this->model->findOrFail($id)->update($data);
        $this->clearCache($this->model->find($id));
        return $result;
    }

    public function delete(int $id): bool
    {
        $result = $this->model->findOrFail($id)->delete();
        $this->clearCache();
        return $result;
    }

    public function paginate(int $perPage = 15, array $criteria = []): LengthAwarePaginator
    {
        return $this->executeWithCache(__FUNCTION__, [$perPage, $criteria], function () use ($perPage, $criteria) {
            return $this->model->where($criteria)->paginate($perPage);
        });
    }

    protected function getQuery(): Builder
    {
        return $this->model->newQuery();
    }
}
