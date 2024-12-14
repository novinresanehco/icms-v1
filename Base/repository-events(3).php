<?php

namespace App\Core\Repositories\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

abstract class RepositoryEvent
{
    use Dispatchable;

    public Model $model;
    public array $data;

    public function __construct(Model $model, array $data = [])
    {
        $this->model = $model;
        $this->data = $data;
    }
}

class ModelCreated extends RepositoryEvent {}
class ModelUpdated extends RepositoryEvent {}
class ModelDeleted extends RepositoryEvent {}
class ModelRestored extends RepositoryEvent {}

namespace App\Core\Repositories\Traits;

use App\Core\Repositories\Events\{ModelCreated, ModelUpdated, ModelDeleted, ModelRestored};
use Illuminate\Database\Eloquent\Model;

trait FiresRepositoryEvents
{
    protected function fireCreateEvent(Model $model, array $data = []): void
    {
        event(new ModelCreated($model, $data));
    }

    protected function fireUpdateEvent(Model $model, array $data = []): void
    {
        event(new ModelUpdated($model, $data));
    }

    protected function fireDeleteEvent(Model $model): void
    {
        event(new ModelDeleted($model));
    }

    protected function fireRestoreEvent(Model $model): void
    {
        event(new ModelRestored($model));
    }
}
