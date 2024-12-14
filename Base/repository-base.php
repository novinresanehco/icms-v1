<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
            DB::beginTransaction();
            $result = $callback();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RuntimeException("Transaction failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function executeQuery(callable $callback)
    {
        try {
            return $callback();
        } catch (\Exception $e) {
            throw new RuntimeException("Query failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function find($id): ?Model
    {
        return $this->executeQuery(function() use ($id) {
            return $this->model->find($id);
        });
    }

    public function findOrFail($id): Model
    {
        return $this->executeQuery(function() use ($id) {
            return $this->model->findOrFail($id);
        });
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

    public function all(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model->all();
        });
    }
}
