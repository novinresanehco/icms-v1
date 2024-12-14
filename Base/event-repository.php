<?php

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\RepositoryInterface;
use App\Core\Repositories\Traits\FiresRepositoryEvents;
use Illuminate\Database\Eloquent\Model;

class EventAwareRepository implements RepositoryInterface
{
    use FiresRepositoryEvents;

    protected RepositoryInterface $repository;

    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function create(array $data): Model
    {
        $model = $this->repository->create($data);
        $this->fireCreateEvent($model, $data);
        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->repository->update($id, $data);
        $this->fireUpdateEvent($model, $data);
        return $model;
    }

    public function delete(int $id): bool
    {
        $model = $this->repository->find($id);
        $result = $this->repository->delete($id);
        
        if ($result) {
            $this->fireDeleteEvent($model);
        }
        
        return $result;
    }

    // Delegate other methods to repository
    public function __call($method, $arguments)
    {
        return $this->repository->{$method}(...$arguments);
    }
}
