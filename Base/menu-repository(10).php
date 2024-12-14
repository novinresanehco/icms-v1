<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\MenuRepositoryInterface;
use App\Core\Models\Menu;
use App\Core\Exceptions\MenuRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{Cache, DB};

class MenuRepository implements MenuRepositoryInterface
{
    protected Menu $model;
    protected const CACHE_PREFIX = 'menu:';
    protected const CACHE_TTL = 3600;

    public function __construct(Menu $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $menu = $this->model->create([
                'name' => $data['name'],
                'location' => $data['location'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
                'language' => $data['language'] ?? config('app.locale'),
            ]);

            if (!empty($data['items'])) {
                $this->syncItems($menu, $data['items']);
            }

            DB::commit();
            $this->clearCache();

            return $menu->load('items');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MenuRepositoryException("Failed to create menu: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $menu = $this->findById($id);
            
            $menu->update([
                'name' => $data['name'] ?? $menu->name,
                'location' => $data['location'] ?? $menu->location,
                'description' => $data['description'] ?? $menu->description,
                'status' => $data['status'] ?? $menu->status,
                'language' => $data['language'] ?? $menu->language,
            ]);

            if (isset($data['items'])) {
                $this->syncItems($menu, $data['items']);
            }

            DB::commit();
            $this->clearCache();

            return $menu->load('items');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MenuRepositoryException("Failed to update menu: {$e->getMessage()}", 0, $e);
        }
    }

    protected function syncItems(Model $menu, array $items, ?int $parentId = null): void
    {
        foreach ($items as $order => $item) {
            $menuItem = $menu->items()->create([
                'parent_id' => $parentId,
                'title' => $item['title'],
                'url' => $item['url'] ?? null,
                'route' => $item['route'] ?? null,
                'parameters' => $item['parameters'] ?? [],
                'icon' => $item['icon'] ?? null,
                'target' => $item['target'] ?? '_self',
                'classes' => $item['classes'] ?? null,
                'order' => $order,
            ]);

            if (!empty($item['children'])) {
                $this->syncItems($menu, $item['children'], $menuItem->id);
            }
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with(['items' => function ($query) {
                $query->orderBy('order');
            }])->findOrFail($id)
        );
    }

    public function findByLocation(string $location, ?string $language = null): ?Model
    {
        $cacheKey = self::CACHE_PREFIX . "location:{$location}";
        if ($language) {
            $cacheKey .= ":{$language}";
        }

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($location, $language) {
                $query = $this->model->where('location', $location)
                    ->where('status', 'active')
                    ->with(['items' => function ($query) {
                        $query->orderBy('order');
                    }]);

                if ($language) {
                    $query->where('language', $language);
                }

                return $query->first();
            }
        );
    }

    public function getAllActive(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'active',
            self::CACHE_TTL,
            fn () => $this->model->where('status', 'active')
                ->with(['items' => function ($query) {
                    $query->orderBy('order');
                }])
                ->get()
        );
    }

    public function reorderItems(int $menuId, array $order): bool
    {
        try {
            DB::beginTransaction();

            $menu = $this->findById($menuId);

            foreach ($order as $position => $itemId) {
                $menu->items()->where('id', $itemId)->update(['order' => $position]);
            }

            DB::commit();
            $this->clearCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MenuRepositoryException("Failed to reorder menu items: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $menu = $this->findById($id);
            $menu->items()->delete();
            $deleted = $menu->delete();

            DB::commit();
            $this->clearCache();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MenuRepositoryException("Failed to delete menu: {$e->getMessage()}", 0, $e);
        }
    }

    public function cloneMenu(int $id, array $data = []): Model
    {
        try {
            DB::beginTransaction();

            $sourceMenu = $this->findById($id);
            
            $clonedMenu = $this->model->create([
                'name' => $data['name'] ?? $sourceMenu->name . ' (Copy)',
                'location' => $data['location'] ?? $sourceMenu->location . '_copy',
                'description' => $sourceMenu->description,
                'status' => $data['status'] ?? 'draft',
                'language' => $data['language'] ?? $sourceMenu->language,
            ]);

            $this->cloneMenuItems($sourceMenu, $clonedMenu);

            DB::commit();
            $this->clearCache();

            return $clonedMenu->load('items');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MenuRepositoryException("Failed to clone menu: {$e->getMessage()}", 0, $e);
        }
    }

    protected function cloneMenuItems(Model $sourceMenu, Model $targetMenu, ?int $parentId = null): void
    {
        $items = $sourceMenu->items()
            ->where('parent_id', $parentId)
            ->orderBy('order')
            ->get();

        foreach ($items as $item) {
            $newItem = $targetMenu->items()->create([
                'parent_id' => $parentId,
                'title' => $item->title,
                'url' => $item->url,
                'route' => $item->route,
                'parameters' => $item->parameters,
                'icon' => $item->icon,
                'target' => $item->target,
                'classes' => $item->classes,
                'order' => $item->order,
            ]);

            // Clone children recursively
            $this->cloneMenuItems($sourceMenu, $targetMenu, $newItem->id);
        }
    }

    protected function clearCache(): void
    {
        Cache::tags(['menus'])->flush();
    }
}
