<?php

namespace App\Core\Repository;

use App\Models\Menu;
use App\Core\Events\MenuEvents;
use App\Core\Exceptions\MenuRepositoryException;
use Illuminate\Support\Collection;

class MenuRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Menu::class;
    }

    /**
     * Get menu structure with items
     */
    public function getMenuStructure(int $menuId): ?Menu
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('structure', $menuId),
            $this->cacheTime,
            fn() => $this->model->with(['items' => function($query) {
                $query->orderBy('order')
                      ->with('children');
            }])->find($menuId)
        );
    }

    /**
     * Create menu item
     */
    public function createMenuItem(int $menuId, array $data): MenuItem
    {
        try {
            $menu = $this->find($menuId);
            if (!$menu) {
                throw new MenuRepositoryException("Menu not found with ID: {$menuId}");
            }

            $item = $menu->items()->create($data);
            $this->clearCache();
            
            event(new MenuEvents\MenuItemCreated($item));
            return $item;

        } catch (\Exception $e) {
            throw new MenuRepositoryException(
                "Failed to create menu item: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update menu items order
     */
    public function updateItemsOrder(array $items): void
    {
        try {
            DB::transaction(function() use ($items) {
                foreach ($items as $order => $itemId) {
                    MenuItem::where('id', $itemId)->update(['order' => $order]);
                }
            });

            $this->clearCache();
            event(new MenuEvents\MenuOrderUpdated($items));

        } catch (\Exception $e) {
            throw new MenuRepositoryException(
                "Failed to update menu order: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get active menus
     */
    public function getActiveMenus(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('active'),
            $this->cacheTime,
            fn() => $this->model->with(['items' => function($query) {
                $query->where('status', 'active')
                      ->orderBy('order');
            }])->where('status', 'active')->get()
        );
    }
}
