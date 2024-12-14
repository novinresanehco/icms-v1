<?php

namespace App\Core\Repositories;

use App\Models\Page;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class PageRepository extends AdvancedRepository
{
    protected $model = Page::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findBySlug(string $slug, ?string $locale = null): ?Page
    {
        return $this->executeQuery(function() use ($slug, $locale) {
            $cacheKey = "page.slug.{$slug}" . ($locale ? ".{$locale}" : '');
            
            return $this->cache->remember($cacheKey, function() use ($slug, $locale) {
                $query = $this->model->where('slug', $slug);
                
                if ($locale) {
                    $query->where('locale', $locale);
                }
                
                return $query->with(['meta', 'translations'])->first();
            });
        });
    }

    public function getPublished(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('pages.published', function() {
                return $this->model
                    ->where('status', 'published')
                    ->orderBy('order')
                    ->get();
            });
        });
    }

    public function updateOrder(array $order): void
    {
        $this->executeTransaction(function() use ($order) {
            foreach ($order as $id => $position) {
                $this->model->find($id)->update(['order' => $position]);
            }
            $this->cache->tags('pages')->flush();
        });
    }

    public function publish(Page $page): void
    {
        $this->executeTransaction(function() use ($page) {
            $page->update([
                'status' => 'published',
                'published_at' => now()
            ]);
            
            $this->cache->tags('pages')->flush();
        });
    }

    public function unpublish(Page $page): void
    {
        $this->executeTransaction(function() use ($page) {
            $page->update([
                'status' => 'draft',
                'published_at' => null
            ]);
            
            $this->cache->tags('pages')->flush();
        });
    }
}
