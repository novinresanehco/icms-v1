<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Repositories\Contracts\{
    PageRepositoryInterface,
    SearchableRepositoryInterface
};
use App\Core\Repositories\{
    PageRepository,
    CategoryRepository,
    TagRepository
};
use App\Core\Repositories\Decorators\{
    CacheablePageRepository,
    EventAwareRepository,
    AuditableRepository,
    SearchableRepository
};

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register base repositories with all decorators
        $this->app->bind(PageRepositoryInterface::class, function ($app) {
            $repository = new PageRepository($app->make('App\Models\Page'));
            
            // Add event awareness
            $repository = new EventAwareRepository($repository);
            
            // Add auditing
            if (config('cms.enable_auditing', true)) {
                $repository = new AuditableRepository($repository);
            }
            
            // Add search capability
            if (config('cms.enable_search', true)) {
                $repository = new SearchableRepository($repository);
            }
            
            // Add caching (outermost layer)
            if (config('cms.enable_repository_cache', true)) {
                $repository = new CacheablePageRepository($repository);
            }
            
            return $repository;
        });

        // Similar bindings for other repositories...
    }
}
