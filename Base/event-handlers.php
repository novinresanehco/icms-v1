<?php

namespace App\Core\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class BaseContentEvent
{
    use Dispatchable, SerializesModels;

    public Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }
}

class ContentCreated extends BaseContentEvent {}
class ContentUpdated extends BaseContentEvent {}
class ContentDeleted extends BaseContentEvent {}

namespace App\Core\Listeners;

use App\Core\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use App\Core\Services\Cache\CacheService;
use App\Core\Services\Search\SearchService;
use App\Core\Services\Audit\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;

class ContentEventSubscriber implements ShouldQueue
{
    protected CacheService $cacheService;
    protected SearchService $searchService;
    protected AuditService $auditService;

    public function __construct(
        CacheService $cacheService,
        SearchService $searchService,
        AuditService $auditService
    ) {
        $this->cacheService = $cacheService;
        $this->searchService = $searchService;
        $this->auditService = $auditService;
    }

    public function handleContentCreated(ContentCreated $event): void
    {
        $this->searchService->index($event->model);
        $this->auditService->log('created', $event->model, [], $event->model->toArray());
        $this->cacheService->invalidateModelCache($event->model);
    }

    public function handleContentUpdated(ContentUpdated $event): void
    {
        $this->searchService->update($event->model);
        $this->auditService->log('updated', $event->model, $event->model->getOriginal(), $event->model->toArray());
        $this->cacheService->invalidateModelCache($event->model);
    }

    public function handleContentDeleted(ContentDeleted $event): void
    {
        $this->searchService->delete($event->model);
        $this->auditService->log('deleted', $event->model, $event->model->toArray(), []);
        $this->cacheService->invalidateModelCache($event->model);
    }

    public function subscribe($events): array
    {
        return [
            ContentCreated::class => 'handleContentCreated',
            ContentUpdated::class => 'handleContentUpdated',
            ContentDeleted::class => 'handleContentDeleted',
        ];
    }
}
