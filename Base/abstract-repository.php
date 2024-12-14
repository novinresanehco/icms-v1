<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use App\Core\Exceptions\RepositoryException;
use Illuminate\Support\Facades\DB;

abstract class AbstractRepository implements RepositoryInterface
{
    protected Model $model;
    
    public function __construct()
    {
        $this->model = $this->makeModel();
    }

    abstract protected function model(): string;

    protected function makeModel(): Model
    {
        $model = app($this->model());

        if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->model()} must be an instance of Model");
        }

        return $model;
    }

    public function find(int|string $id): ?Model
    {
        return $this->model->find($id);
    }

    public function findAll(): Collection
    {
        return $this->model->all();
    }

    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $model = $this->model->create($data);
            $this->afterCreate($model, $data);
            return $model;
        });
    }

    public function update(Model $model, array $data): bool
    {
        return DB::transaction(function () use ($model, $data) {
            $updated = $model->update($data);
            if ($updated) {
                $this->afterUpdate($model, $data);
            }
            return $updated;
        });
    }

    public function delete(Model $model): bool
    {
        return DB::transaction(function () use ($model) {
            $deleted = $model->delete();
            if ($deleted) {
                $this->afterDelete($model);
            }
            return $deleted;
        });
    }

    protected function newQuery(): Builder
    {
        return $this->model->newQuery();
    }

    protected function afterCreate(Model $model, array $data): void
    {
        // Hook for post-create operations
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        // Hook for post-update operations
    }

    protected function afterDelete(Model $model): void
    {
        // Hook for post-delete operations
    }
}
