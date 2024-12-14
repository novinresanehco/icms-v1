<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MenuRepository implements MenuRepositoryInterface
{
    protected Menu $model;
    
    public function __construct(Menu $model)
    {
        $this->model = $model;
    }

    public function createMenu(array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $menu = $this->model->create($data);
            
            if (isset($data['items'])) {
                $this->createMenuItems($menu->id, $data['items']);
            }
            
            DB::commit();
            $this->clearMenuCache($menu->location);
            
            return $menu->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create menu: ' . $e->getMessage());
            return null;
        }
    }
    
    public function updateMenu(int $menuId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $menu = $this->model->findOrFail($menuId);
            $menu->update($data);
            
            if (isset($data['items'])) {
                // Remove existing items
                $menu->items()->delete();
                $this->createMenuItems($menu->id, $data['items']);
            }
            
            DB::commit();
            $this->clearMenuCache($menu->location);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update menu: ' . $e->getMessage());
            return false;
        }
    }
    
    public function deleteMenu(int $menuId): bool
    {
        try {
            DB::beginTransaction();
            
            $menu = $this->model->findOrFail($menuId);
            $menu->items()->delete();
            $menu->delete();
            
            DB::commit();
            $this->clearMenuCache($menu->location);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete menu: ' . $e->getMessage());
            return false;
        }
    }
    
    public function getMenu(int $menuId, bool $withItems = true): ?array
    {
        try {
            $query = $this->model->where('id', $menuId);
            
            if ($withItems) {
                $query->with(['items' => function($q) {
                    $q->orderBy('order')->with('children');
                }]);
            }
            
            $menu = $query->first();
            
            return $menu ? $menu->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get menu: ' . $e->getMessage());
            return null;
        }
    }
    
    public function getMenuByLocation(string $location): ?array
    {
        return Cache::remember("menu.{$location}", 3600, function() use ($location) {
            try {
                $menu = $this->model
                    ->where('location', $location)
                    ->where('status', true)
                    ->with(['activeItems' => function($q) {
                        $q->orderBy('order')->with('activeChildren');
                    }])
                    ->first();
                
                return $menu ? $menu->toArray() : null;
            } catch (\Exception $e) {
                Log::error('Failed to get menu by location: ' . $e->getMessage());
                return null;
            }
        });
    }
    
    public function getAllMenus(): Collection
    {
        try {
            return $this->model->without('items')->get();
        } catch (\Exception $e) {
            Log::error('Failed to get all menus: ' . $e->getMessage());
            return collect();
        }
    }
    
    protected function createMenuItems(int $menuId, array $items, ?int $parentId = null): void
    {
        foreach ($items as $order => $item) {
            $menuItem = $this->model->items()->create([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'title' => $item['title'],
                'url' => $item['url'],
                'target' => $item['target'] ?? '_self',
                'icon_class' => $item['icon_class'] ?? null,
                'order' => $order,
                'status' => $item['status'] ?? true,
            ]);
            
            if (isset($item['children']) && is_array($item['children'])) {
                $this->createMenuItems($menuId, $item['children'], $menuItem->id);
            }
        }
    }
    
    protected function clearMenuCache(string $location): void
    {
        Cache::forget("menu.{$location}");
    }
}
