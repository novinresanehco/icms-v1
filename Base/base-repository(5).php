<?php

namespace App\Core\Repositories\Contracts;

interface RepositoryInterface
{
    public function find($id);
    public function findWhere(array $criteria);
    public function all();
    public function create(array $attributes);
    public function update($id, array $attributes);
    public function delete($id);
    public function paginate($perPage = 15);
}

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find($id)
    {
        $result = $this->model->find($id);

        if (!$result) {
            throw new ModelNotFoundException("Model with ID {$id} not found");
        }

        return $result;
    }

    public function findWhere(array $criteria)
    {
        return $this->model->where($criteria)->get();
    }

    public function all()
    {
        return $this->model->all();
    }

    public function create(array $attributes)
    {
        return $this->model->create($attributes);
    }

    public function update($id, array $attributes)
    {
        $model = $this->find($id);
        $model->update($attributes);
        return $model;
    }

    public function delete($id)
    {
        $model = $this->find($id);
        return $model->delete();
    }

    public function paginate($perPage = 15)
    {
        return $this->model->paginate($perPage);
    }
}
