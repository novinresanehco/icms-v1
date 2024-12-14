<?php

namespace App\Repositories\Contracts;

interface MenuRepositoryInterface
{
    public function create(array $data): \App\Models\Menu;
    public function update(int $id, array $data): \App\Models\Menu;
    public function delete(int $id): bool;
    public function find(int $id): ?\App\Models\Menu;
    public function findByLocation(string $location): ?\App\Models\Menu;
    public function getActive(): \Illuminate\Database\Eloquent\Collection;
    public function addMenuItem(\App\Models\Menu $menu, array $data): \App\Models\MenuItem;
    public function updateMenuItem(int $itemId, array $data): \App\Models\MenuItem;
    public function deleteMenuItem(int $itemId): bool;
    public function reorderItems(array $items): bool;
}
