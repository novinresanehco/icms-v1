<?php

namespace App\Repositories\Contracts;

use App\Models\Menu;
use Illuminate\Support\Collection;

interface MenuRepositoryInterface
{
    public function findByLocation(string $location): ?Menu;
    public function getActiveMenus(): Collection;
    public function createWithItems(array $data, array $items): Menu;
    public function updateWithItems(int $id, array $data, array $items): bool;
    public function reorderItems(int $menuId, array $order): bool;
    public function addItem(int $menuId, array $itemData): bool;
    public function removeItem(int $menuId, int $itemId): bool;
    public function updateItem(int $menuId, int $itemId, array $data): bool;
    public function getMenuTree(int $menuId): array;
    public function duplicateMenu(int $id, string $newName): Menu;
}
