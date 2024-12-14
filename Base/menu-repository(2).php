<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Repositories\Contracts\MenuRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MenuRepository extends BaseRepository implements MenuRepositoryInterface
{
    protected array $searchableFields = ['name', 'location'];
    protected array $filterableFields = ['status', 'type'];

    public function getByLocation(string $location): ?Menu
    {
        return Cache::tags(['menus'])->remember(
            "menu.{$location}",
            3600,
            fn() => $this->model
                ->where('location', $location)
                ->where('status', 'active')
                ->first()
        );
    }

    public function updateMenuItems(int $menuId, array $items): bool
    {
        try {
            $menu = $this->findById($menuId);
            $menu->items = $items;
            $menu->save();
            
            Cache::tags(['menus'])->flush();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating menu items: ' . $e->getMessage());
            return false;
        }
    }

    public function getActiveMenus(): Collection
    {
        return $this->model
            ->where('status', 'active')
            ->orderBy('location')
            ->get();
    }

    public function validateMenuItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!isset($item['type']) || !isset($item['title'])) {
                return false;
            }

            if (isset($item['children']) && is_array($item['children'])) {
                if (!$this->validateMenuItems($item['children'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
