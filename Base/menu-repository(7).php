<?php

namespace App\Repositories;

use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class MenuRepository implements MenuRepositoryInterface 
{
    protected string $menusTable = 'menus';
    protected string $menuItemsTable = 'menu_items';

    public function createMenu(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $menuId = DB::table($this->menusTable)->insertGetId([
                'name' => $data['name'],
                'slug' => \Str::slug($data['name']),
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            if (!empty($data['items'])) {
                $this->saveMenuItems($menuId, $data['items']);
            }

            $this->clearMenuCache();
            DB::commit();

            return $menuId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create menu: ' . $e->getMessage());
            return null;
        }
    }

    public function updateMenu(int $menuId, array $data): bool
    {
        try {
            DB::beginTransaction();

            $updated = DB::table($this->menusTable)
                ->where('id', $menuId)
                ->update([
                    'name' => $data['name'],
                    'slug' => \Str::slug($data['name']),
                    'location' => $data['location'] ?? null,
                    'description' => $data['description'] ?? null,
                    'updated_at' => now()
                ]) > 0;

            if (isset($data['items'])) {
                DB::table($this->menuItemsTable)
                    ->where('menu_id', $menuId)
                    ->delete();

                $this->saveMenuItems($menuId, $data['items']);
            }

            $this->clearMenuCache();
            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update menu: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteMenu(int $menuId): bool
    {
        try {
            DB::beginTransaction();

            // Delete menu items first
            DB::table($this->menuItemsTable)
                ->where('menu_id', $menuId)
                ->delete();

            // Delete menu
            $deleted = DB::table($this->menusTable)
                ->where('id', $menuId)
                ->delete() > 0;

            if ($deleted) {
                $this->clearMenuCache();
            }

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete menu: ' . $e->getMessage());
            return false;
        }
    }

    public function getMenu(int $menuId, bool $withItems = true): ?array
    {
        try {
            $menu = DB::table($this->menusTable)
                ->where('id', $menuId)
                ->first();

            if (!$menu) {
                return null;
            }

            $menuArray = (array) $menu;

            if ($withItems) {
                $menuArray['items'] = $this->getMenuItemHierarchy($menuId);
            }

            return $menuArray;
        } catch (\Exception $e) {
            \Log::error('Failed to get menu: ' . $e->getMessage());
            return null;
        }
    }

    public function getMenuByLocation(string $location): ?array
    {
        $cacheKey = "menu_location_{$location}";

        return Cache::remember($cacheKey, 3600, function() use ($location) {
            try {
                $menu = DB::table($this->menusTable)
                    ->where('location', $location)
                    ->first();

                if (!$menu) {
                    return null;
                }

                $menuArray = (array) $menu;
                $menuArray['items'] = $this->getMenuItemHierarchy($menu->id);

                return $menuArray;
            } catch (\Exception $e) {
                \Log::error('Failed to get menu by location: ' . $e->getMessage());
                return null;
            }
        });
    }

    public function getAllMenus(): Collection
    {
        return Cache::remember('all_menus', 3600, function() {
            return collect(DB::table($this->menusTable)
                ->orderBy('name')
                ->get());
        });
    }

    protected function saveMenuItems(int $menuId, array $items, ?int $parentId = null): void
    {
        foreach ($items as $index => $item) {
            $itemId = DB::table($this->menuItemsTable)->insertGetId([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'title' => $item['title'],
                'url' => $item['url'],
                'target' => $item['target'] ?? '_self',
                'class' => $item['class'] ?? null,
                'icon' => $item['icon'] ?? null,
                'type' => $item['type'] ?? 'custom',
                'object_id' => $item['object_id'] ?? null,
                'order' => $index,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            if (!empty($item['children'])) {
                $this->saveMenuItems($menuId, $item['children'], $itemId);
            }
        }
    }

    protected function getMenuItemHierarchy(int $menuId): array
    {
        $items = collect(DB::table($this->menuItemsTable)
            ->where('menu_id', $menuId)
            ->orderBy('parent_id')
            ->orderBy('order')
            ->get());

        return $this->buildItemHierarchy($items);
    }

    protected function buildItemHierarchy(Collection $items, ?int $parentId = null): array
    {
        $hierarchy = [];

        foreach ($items->where('parent_id', $parentId) as $item) {
            $children = $this->buildItemHierarchy($items, $item->id);
            $hierarchy[] = array_merge(
                (array) $item,
                ['children' => $children]
            );
        }

        return $hierarchy;
    }

    protected function clearMenuCache(): void
    {
        Cache::forget('all_menus');
        Cache::tags(['menus'])->flush();

        // Clear location-specific caches
        $locations = DB::table($this->menusTable)
            ->whereNotNull('location')
            ->pluck('location');

        foreach ($locations as $location) {
            Cache::forget("menu_location_{$location}");
        }
    }
}
