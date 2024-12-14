<?php

namespace App\Core\Events;

abstract class RepositoryEvent
{
    public $model;
    public $repository;

    public function __construct($model, $repository)
    {
        $this->model = $model;
        $this->repository = $repository;
    }
}

class RepositoryCreated extends RepositoryEvent {}
class RepositoryUpdated extends RepositoryEvent {}
class RepositoryDeleted extends RepositoryEvent {}

// Event Listeners
namespace App\Core\Listeners;

class RepositoryEventSubscriber
{
    public function handleRepositoryCreated(RepositoryCreated $event)
    {
        // Log creation
        activity()
            ->performedOn($event->model)
            ->log('created');
        
        // Clear relevant caches
        Cache::tags($event->repository->getCacheTags())->flush();
    }

    public function handleRepositoryUpdated(RepositoryUpdated $event)
    {
        // Log update
        activity()
            ->performedOn($event->model)
            ->log('updated');
            
        // Clear relevant caches
        Cache::tags($event->repository->getCacheTags())->flush();
    }

    public function handleRepositoryDeleted(RepositoryDeleted $event)
    {
        // Log deletion
        activity()
            ->performedOn($event->model)
            ->log('deleted');
            
        // Clear relevant caches
        Cache::tags($event->repository->getCacheTags())->flush();
    }

    public function subscribe($events)
    {
        $events->listen(
            RepositoryCreated::class,
            [RepositoryEventSubscriber::class, 'handleRepositoryCreated']
        );

        $events->listen(
            RepositoryUpdated::class,
            [RepositoryEventSubscriber::class, 'handleRepositoryUpdated']
        );

        $events->listen(
            RepositoryDeleted::class,
            [RepositoryEventSubscriber::class, 'handleRepositoryDeleted']
        );
    }
}
