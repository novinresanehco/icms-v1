<?php

namespace App\Core\Repositories\Listeners;

use App\Core\Repositories\Events\{BeforeCreate, AfterCreate, BeforeUpdate, AfterUpdate};
use App\Core\Services\Cache\CacheService;
use App\Core\Services\Search\SearchService;
use App\Core\Services\Audit\AuditService;

class RepositoryEventSubscriber
{
    protected CacheService $cache;
    protected SearchService $search;
    protected AuditService $audit;

    public function __construct(
        CacheService $cache,
        SearchService $search,
        AuditService $audit
    ) {
        $this->cache = $cache;
        $this->search = $search;
        $this->audit = $audit;
    }

    public function handleBeforeCreate(BeforeCreate $event): void
    {
        // Validate business rules
        $this->validateBusinessRules($event);
        
        // Prepare data
        $this->prepareData($event);
    }

    public function handleAfterCreate(AfterCreate $event): void
    {
        // Update search index
        $this->search->index($event->model);
        
        // Clear relevant caches
        $this->cache->tags([$event->model->getTable()])->flush();
        
        // Record audit
        $this->audit->log('create', $event->model, [], $event->model->toArray());
    }

    public function handleBeforeUpdate(BeforeUpdate $event): void
    {
        // Validate update rules
        $this->validateUpdateRules($event);
        
        // Store original state
        $event->metadata['original'] = $event->model->getOriginal();
    }

    public function handleAfterUpdate(AfterUpdate $event): void
    {
        // Update search index
        $this->search->update($event->model);
        
        // Clear relevant caches
        $this->cache->tags([$event->model->getTable()])->flush();
        
        // Record audit
        $this->audit->log(
            'update',
            $event->model,
            $event->metadata['original'],
            $event->model->toArray()
        );
    }

    protected function validateBusinessRules(BeforeCreate $event): void
    {
        // Add business rule validation logic
    }

    protected function prepareData(BeforeCreate $event): void
    {
        // Add data preparation logic
    }

    protected function validateUpdateRules(BeforeUpdate $event): void
    {
        // Add update validation logic
    }

    public function subscribe($events): array
    {
        return [
            BeforeCreate::class => 'handleBeforeCreate',
            AfterCreate::class => 'handleAfterCreate',
            BeforeUpdate::class => 'handleBeforeUpdate',
            AfterUpdate::class => 'handleAfterUpdate'
        ];
    }
}
