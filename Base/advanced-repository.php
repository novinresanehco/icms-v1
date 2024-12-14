<?php

namespace App\Core\Repositories;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\RepositoryException;

abstract class AdvancedRepository
{
    protected $model;
    
    public function __construct()
    {
        $this->model = app($this->model);
    }

    protected function executeTransaction(callable $callback)
    {
        try {
            return DB::transaction(function() use ($callback) {
                return $callback();
            });
        } catch (Exception $e) {
            throw new RepositoryException($e->getMessage(), 0, $e);
        }
    }

    protected function executeQuery(callable $callback)
    {
        try {
            return $callback();
        } catch (Exception $e) {
            throw new RepositoryException($e->getMessage(), 0, $e);
        }
    }

    public function create(array $data): Model
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->model->create($data);
        });
    }

    public function update(Model $model, array $data): bool
    {
        return $this->executeTransaction(function() use ($model, $data) {
            return $model->update($data);
        });
    }

    public function delete(Model $model): bool
    {
        return $this->executeTransaction(function() use ($model) {
            return $model->delete();
        });
    }

    public function find(int $id): ?Model
    {
        return $this->executeQuery(function() use ($id) {
            return $this->model->find($id);
        });
    }

    public function findOrFail(int $id): Model
    {
        return $this->executeQuery(function() use ($id) {
            return $this->model->findOrFail($id);
        });
    }

    public function all(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model->all();
        });
    }

    public function paginate(int $perPage = 15)
    {
        return $this->executeQuery(function() use ($perPage) {
            return $this->model->paginate($perPage);
        });
    }
}
