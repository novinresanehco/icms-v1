<?php

namespace App\Core\Menu\Repository;

use App\Core\Menu\Models\Menu;
use App\Core\Menu\Models\MenuItem;
use App\Core\Menu\DTO\MenuData;
use App\Core\Menu\DTO\MenuItemData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface MenuRepositoryInterface extends RepositoryInterface
{
    /**
     * Find menu by slug.
     *
     * @param string $slug
     * @return Menu|null
     */
    public function findBySlug(string $slug): ?Menu;

    /**
     * Get menu with all items.
     *
     * @param int $id
     * @return Menu
     */
    public function getWithItems(int $id): Menu;

    /**
     * Create menu item.
     *
     * @param int $menuId
     * @param MenuItemData $data
     * @return MenuItem
     */
    public function createMenuItem(int $menuId, MenuItemData $data): MenuItem;

    /**
     * Update menu item.
     *
     * @param int $itemId
     * @param MenuItemData $data
     * @return MenuItem
     */
    public function updateMenuItem(int $itemId, MenuItemData $data): MenuItem;

    /**
     * Delete menu item.
     *
     * @param int $itemId
     * @return bool
     */
    public function deleteMenuItem(int $itemId): bool;

    /**
     * Update menu items order.
     *
     * @param int $menuId
     * @param array $order Array of item IDs in order
     * @return bool
     */
    public function updateItemsOrder(int $menuId, array $order): bool;

    /**
     * Move menu item to new position.
     *
     * @param int $itemId
     * @param int|null $parentId
     * @param int $order
     * @return MenuItem
     */
    public function moveMenuItem(int $itemId, ?int $parentId, int $order): MenuItem;

    /**
     * Get active menus.
     *
     * @return Collection
     */
    public function getActiveMenus(): Collection;

    /**
     * Get menu items tree.
     *
     * @param int $menuId
     * @return array
     */
    public function getMenuTree(int $menuId): array;

    /**
     * Clone menu with all items.
     *
     * @param int $menuId
     * @param array $overrides
     * @return Menu
     */
    public function cloneMenu(int $menuId, array $overrides = []): Menu;

    /**
     * Get menu usage statistics.
     *
     * @param int $menuId
     * @return array
     */
    public function getMenuStats(int $menuId): array;

    /**
     * Export menu structure.
     *
     * @param int $menuId
     * @return array
     */
    public function exportMenu(int $menuId): array;

    /**
     * Import menu structure.
     *
     * @param array $data
     * @return Menu
     */
    public function importMenu(array $data): Menu;
}
