<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuRepository implements MenuRepositoryInterface
{
    protected Menu $model;
    protected MenuItem $menuItem;
    
    public function __construct(Menu $menu, MenuItem $menuItem)
    {
        $this->model = $menu;
        $this->menuItem = $menuItem;
    }

    public function create(array $data): Menu
    {
        DB::beginTransaction();
        try {
            $menu = $this->model->create($data);
            DB::commit();
            $this->clearMenuCache($menu->location);
            return $menu;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create menu: ' . $e->getMessage());
            throw $e;
        }
    }

    public function update(int $id, array $data): Menu
    {
        DB::beginTransaction();
        try {
            $menu = $this->find($id);
            if (!$menu) {
                throw new \Exception('Menu not found');
            }
            
            $menu->update($data);
            DB::commit();
            $this->clearMenuCache($menu->location);
            return $menu;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update menu: ' . $e->getMessage());
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $menu = $this->find($id);
            if (!$menu) {
                throw new \Exception('Menu not found');
            }
            
            $menu->delete();
            DB::commit();
            $this->clearMenuCache($menu->location);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete menu: ' . $e->getMessage());
            throw $e;
        }
    }

    public function find(int $id): ?Menu
    {
        return Cache::remember(
            "menu.{$id}",
            config('cache.ttl', 3600),
            fn() => $this->model->with('items')->find($id)
        );
    }

    public function findByLocation(string $location): ?Menu
    {
        return Cache::remember(
            "menu.location.{$location}",
            config('cache.ttl', 3600),
            fn() => $this->model->with('items')->where('location', $location)->first()
        );
    }

    public function getActive(): Collection
    {
        return Cache::remember(
            'menus.active',
            config('cache.ttl', 3600),
            fn() => $this->model->with('items')->where('is_active', true)->get()
        );
    }

    public function addMenuItem(Menu $menu, array $data): MenuItem
    {
        DB::beginTransaction();
        try {
            $item = $menu->items()->create($data);
            DB::commit();
            $this->clearMenuCache($menu->location);
            return $item;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add menu item: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateMenuItem(int $itemId, array $data): MenuItem
    {
        DB::beginTransaction();
        try {
            $item = $this->menuItem->findOrFail($itemId);
            $item->update($data);
            DB::commit();
            $this->clearMenuCache($item->menu->location);
            return $item;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update menu item: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteMenuItem(int $itemId): bool
    {
        DB::beginTransaction();
        try {
            $item = $this->menuItem->findOrFail($itemId);
            $location = $item->menu->location;
            $item->delete();
            DB::commit();
            $this->clearMenuCache($location);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete menu item: ' . $e->getMessage());
            throw $e;
        }
    }

    public function reorderItems(array $items): bool
    {
        DB::beginTransaction();
        try {
            foreach ($items as $order => $itemId) {
                $this->menuItem->where('id', $itemId)->update(['order' => $order]);
            }
            DB::commit();
            // Clear all menu caches as this could affect multiple menus
            Cache::tags(['menus'])->flush();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reorder menu items: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function clearMenuCache(string $location): void
    {
        Cache::tags(['menus'])->flush();
        Cache::forget("menu.location.{$location}");
    }
}
