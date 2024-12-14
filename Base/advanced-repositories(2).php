<?php

namespace App\Core\Repositories;

// Cache aware repository interface
interface CacheableRepositoryInterface extends RepositoryInterface
{
    public function getCacheKey(string $method, array $params = []): string;
    public function clearCache(string $tag = null): void;
    public function getCacheTags(): array;
}

abstract class CacheableRepository extends BaseRepository implements CacheableRepositoryInterface
{
    protected int $cacheTime = 3600;
    protected array $cacheTags = [];

    public function getCacheKey(string $method, array $params = []): string 
    {
        $model = $this->model();
        $paramsKey = md5(serialize($params));
        return "repo.{$model}.{$method}.{$paramsKey}";
    }

    public function getCacheTags(): array
    {
        return array_merge([$this->model()], $this->cacheTags);
    }

    public function clearCache(string $tag = null): void
    {
        if ($tag) {
            Cache::tags($tag)->flush();
        } else {
            Cache::tags($this->getCacheTags())->flush();
        }
    }

    protected function remember(string $key, callable $callback)
    {
        return Cache::tags($this->getCacheTags())
            ->remember($key, $this->cacheTime, $callback);
    }
}

// Settings Repository
namespace App\Repositories;

class SettingsRepository extends CacheableRepository
{
    protected array $cacheTags = ['settings'];

    protected function model(): string
    {
        return Setting::class;
    }

    public function get(string $key, $default = null)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$key]),
            fn() => $this->model->where('key', $key)->value('value') ?? $default
        );
    }

    public function set(string $key, $value): void
    {
        $this->model->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
        
        $this->clearCache();
    }

    public function getAll(): array
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__),
            fn() => $this->model->pluck('value', 'key')->toArray()
        );
    }
}

// Taxonomy Repository
class TaxonomyRepository extends CacheableRepository
{
    protected array $cacheTags = ['taxonomies'];

    protected function model(): string
    {
        return Taxonomy::class;
    }

    public function findByType(string $type)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$type]),
            fn() => $this->model->where('type', $type)
                ->with('terms')
                ->get()
        );
    }

    public function findTerms(string $taxonomy)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$taxonomy]),
            fn() => $this->model->where('slug', $taxonomy)
                ->firstOrFail()
                ->terms()
                ->orderBy('name')
                ->get()
        );
    }

    public function attachTerms(int $taxonomyId, array $termIds): void
    {
        DB::transaction(function() use ($taxonomyId, $termIds) {
            $taxonomy = $this->find($taxonomyId);
            $taxonomy->terms()->sync($termIds);
        });
        
        $this->clearCache();
    }
}

// Template Repository
class TemplateRepository extends CacheableRepository
{
    protected array $cacheTags = ['templates'];

    protected function model(): string
    {
        return Template::class;
    }

    public function findActive()
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__),
            fn() => $this->model->where('active', true)->get()
        );
    }

    public function findByTheme(string $theme)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$theme]),
            fn() => $this->model->where('theme', $theme)
                ->orderBy('name')
                ->get()
        );
    }

    public function setActive(int $id): void
    {
        DB::transaction(function() use ($id) {
            // Deactivate all templates
            $this->model->where('active', true)->update(['active' => false]);
            
            // Activate selected template
            $this->update($id, ['active' => true]);
        });
        
        $this->clearCache();
    }
}

// Menu Repository
class MenuRepository extends CacheableRepository
{
    protected array $cacheTags = ['menus'];

    protected function model(): string
    {
        return Menu::class;
    }

    public function findWithItems(int $id)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$id]),
            fn() => $this->model->with(['items' => function($query) {
                $query->orderBy('order');
            }])->findOrFail($id)
        );
    }

    public function findByLocation(string $location)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$location]),
            fn() => $this->model->where('location', $location)
                ->with(['items' => function($query) {
                    $query->orderBy('order');
                }])
                ->first()
        );
    }

    public function updateItems(int $menuId, array $items): void
    {
        DB::transaction(function() use ($menuId, $items) {
            $menu = $this->find($menuId);
            
            // Delete existing items
            $menu->items()->delete();
            
            // Create new items
            foreach ($items as $order => $item) {
                $item['order'] = $order;
                $menu->items()->create($item);
            }
        });
        
        $this->clearCache();
    }
}
