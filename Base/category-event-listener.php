<?php

namespace App\Core\Listeners;

use App\Core\Events\{CategoryCreated, CategoryUpdated, CategoryDeleted};
use Illuminate\Support\Facades\{Cache, Log};
use Illuminate\Events\Dispatcher;
use App\Core\Services\SearchService;

class CategoryEventSubscriber
{
    protected SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function handleCategoryCreated(CategoryCreated $event): void
    {
        try {
            $this->clearCategoryCache();
            $this->searchService->indexCategory($event->category);
            Log::info('Category created:', ['id' => $event->category->id]);
        } catch (\Exception $e) {
            Log::error('Error handling category creation:', ['error' => $e->getMessage()]);
        }
    }

    public function handleCategoryUpdated(CategoryUpdated $event): void
    {
        try {
            $this->clearCategoryCache();
            $this->searchService->updateCategoryIndex($event->category);
            Log::info('Category updated:', ['id' => $event->category->id]);
        } catch (\Exception $e) {
            Log::error('Error handling category update:', ['error' => $e->getMessage()]);
        }
    }

    public function handleCategoryDeleted(CategoryDeleted $event): void
    {
        try {
            $this->clearCategoryCache();
            $this->searchService->removeCategoryFromIndex($event->category->id);
            Log::info('Category deleted:', ['id' => $event->category->id]);
        } catch (\Exception $e) {
            Log::error('Error handling category deletion:', ['error' => $e->getMessage()]);
        }
    }

    protected function clearCategoryCache(): void
    {
        Cache::tags(['categories'])->flush();
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            CategoryCreated::class => 'handleCategoryCreated',
            CategoryUpdated::class => 'handleCategoryUpdated',
            CategoryDeleted::class => 'handleCategoryDeleted',
        ];
    }
}
