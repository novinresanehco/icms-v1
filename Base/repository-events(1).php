<?php

namespace App\Subscribers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Events\Repository\{
    EntityCreated,
    EntityUpdated,
    EntityDeleted
};

class RepositoryEventSubscriber
{
    public function handleEntityCreated(EntityCreated $event): void
    {
        $this->logRepositoryAction($event->getEntity(), 'created');
        $this->clearRelatedCaches($event->getEntity());
    }

    public function handleEntityUpdated(EntityUpdated $event): void
    {
        $this->logRepositoryAction($event->getEntity(), 'updated');
        $this->clearRelatedCaches($event->getEntity());
    }

    public function handleEntityDeleted(EntityDeleted $event): void
    {
        $this->logRepositoryAction($event->getEntity(), 'deleted');
        $this->clearRelatedCaches($event->getEntity());
    }

    protected function logRepositoryAction($entity, string $action): void
    {
        Log::info("Repository entity {$action}", [
            'entity' => get_class($entity),
            'id' => $entity->id,
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    protected function clearRelatedCaches($entity): void
    {
        $entityType = strtolower(class_basename($entity));
        
        // Clear entity-specific cache
        Cache::tags([$entityType])->flush();
        
        // Clear related caches based on entity type
        switch ($entityType) {
            case 'content':
                Cache::tags(['category', 'tag'])->flush();
                break;
            case 'category':
                Cache::tags(['content'])->flush();
                break;
            case 'tag':
                Cache::tags(['content'])->flush();
                break;
        }
    }

    public function subscribe($events): void
    {
        $events->listen(
            EntityCreated::class,
            [RepositoryEventSubscriber::class, 'handleEntityCreated']
        );

        $events->listen(
            EntityUpdated::class,
            [RepositoryEventSubscriber::class, 'handleEntityUpdated']
        );

        $events->listen(
            EntityDeleted::class,
            [RepositoryEventSubscriber::class, 'handleEntityDeleted']
        );
    }
}
