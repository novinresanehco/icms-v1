<?php

namespace App\Core\Repositories\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class RepositoryEvent
{
    use Dispatchable, SerializesModels;

    public Model $model;
    public string $repositoryClass;
    public array $metadata;

    public function __construct(Model $model, string $repositoryClass, array $metadata = [])
    {
        $this->model = $model;
        $this->repositoryClass = $repositoryClass;
        $this->metadata = $metadata;
    }
}

class BeforeCreate extends RepositoryEvent {}
class AfterCreate extends RepositoryEvent {}
class BeforeUpdate extends RepositoryEvent {}
class AfterUpdate extends RepositoryEvent {}
class BeforeDelete extends RepositoryEvent {}
class AfterDelete extends RepositoryEvent {}
class BeforeRestore extends RepositoryEvent {}
class AfterRestore extends RepositoryEvent {}

class RepositoryEventDispatcher
{
    protected array $listeners = [];

    public function addListener(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(RepositoryEvent $event): void
    {
        $eventClass = get_class($event);
        
        if (isset($this->listeners[$eventClass])) {
            foreach ($this->listeners[$eventClass] as $listener) {
                call_user_func($listener, $event);
            }
        }
    }
}

trait HasEvents
{
    protected function fireEvent(string $eventClass, array $metadata = []): void
    {
        $event = new $eventClass(
            $this->model,
            static::class,
            array_merge($this->getDefaultMetadata(), $metadata)
        );

        app(RepositoryEventDispatcher::class)->dispatch($event);
    }

    protected function getDefaultMetadata(): array
    {
        return [
            'timestamp' => now(),
            'user_id' => auth()->id(),
            'ip' => request()->ip()
        ];
    }
}

// Example implementation in ContentRepository
class ContentRepository extends AdvancedRepository
{
    use HasEvents;

    public function create(array $attributes)
    {
        $this->fireEvent(BeforeCreate::class, ['attributes' => $attributes]);
        
        $model = parent::create($attributes);
        
        $this->fireEvent(AfterCreate::class, [
            'attributes' => $attributes,
            'model_id' => $model->id
        ]);

        return $model;
    }

    public function update($id, array $attributes)
    {
        $this->fireEvent(BeforeUpdate::class, [
            'id' => $id,
            'attributes' => $attributes
        ]);
        
        $model = parent::update($id, $attributes);
        
        $this->fireEvent(AfterUpdate::class, [
            'id' => $id,
            'attributes' => $attributes,
            'model' => $model
        ]);

        return $model;
    }
}
