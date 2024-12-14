<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Menu;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface MenuRepositoryInterface
{
    public function find(int $id): ?Menu;
    
    public function findByLocation(string $location): ?Menu;
    
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    
    public function create(array $data): Menu;
    
    public function update(int $id, array $data): Menu;
    
    public function delete(int $id): bool;
    
    public function getItems(int $menuId): Collection;
    
    public function addItem(int $menuId, array $itemData): bool;
    
    public function updateItem(int $itemId, array $itemData): bool;
    
    public function deleteItem(int $itemId): bool;
    
    public function reorderItems(int $menuId, array $order): bool;
    
    public function getLocations(): Collection;
}
