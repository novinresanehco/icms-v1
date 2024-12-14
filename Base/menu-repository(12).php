<?php

namespace App\Core\Repositories;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Support\Collection;

class MenuRepository extends AdvancedRepository
{
    protected $model = Menu::class;

    public function createMenu(string $name, string $location): Menu
    {
        return $this->executeTransaction(function() use ($name, $location) {
            return $this->create([
                'name' => $name,
                'location' => $location,
                'status' => 'active'
            ]);
        });
    }

    public function addMenuItem(int $menuId, array $data, ?int $parentId = null): MenuItem
    {
        return $this->executeTransaction(function() use ($menuId, $data, $parentId) {
            $item = MenuItem::create([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'title' => $data['title'],
                'url' => $data['url'],
                'target' => $data['target'] ?? '_self',
                'order' => $this->getNextOrder($menuId, $parentId),
                'status' => 'active'
            ]);

            $this->invalidateCache('getMenuItems', $menuId);
            return $item;
        });
    }

    public function getMenuItems(int $menuId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($menuId) {
            return MenuItem::where('menu_id', $menuId)
                ->whereNull('parent_id')
                ->with(['children' => function($query) {
                    $query->orderBy('order');
                }])
                ->orderBy('order')
                ->get();
        }, $menuId);
    }

    public function updateItemOrder(int $itemId, int $newOrder): void
    {
        $this->executeTransaction(function() use ($itemId, $newOrder) {
            $item = MenuItem::findOrFail($itemId);
            $item->order = $newOrder;
            $item->save();

            $this->invalidateCache('getMenuItems', $item->menu_id);
        });
    }

    protected function getNextOrder(int $menuId, ?int $parentId): int
    {
        return MenuItem::where('menu_id', $menuId)
            ->where('parent_id', $parentId)
            ->max('order') + 1;
    }

    public function getMenuByLocation(string $location): ?Menu
    {
        return $this->executeWithCache(__METHOD__, function() use ($location) {
            return $this->model
                ->where('location', $location)
                ->where('status', 'active')
                ->first();
        }, $location);
    }

    public function deleteMenuItem(int $itemId): void
    {
        $this->executeTransaction(function() use ($itemId) {
            $item = MenuItem::findOrFail($itemId);
            $menuId = $item->menu_id;

            // Delete children recursively
            $this->deleteChildren($itemId);
            
            // Delete the item itself
            $item->delete();

            $this->invalidateCache('getMenuItems', $menuId);
        });
    }

    protected function deleteChildren(int $parentId): void
    {
        $children = MenuItem::where('parent_id', $parentId)->get();
        
        foreach ($children as $child) {
            $this->deleteChildren($child->id);
            $child->delete();
        }
    }
}
