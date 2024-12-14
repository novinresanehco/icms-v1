<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface MenuRepositoryInterface
{
    public function createMenu(array $data): ?int;
    
    public function updateMenu(int $menuId, array $data): bool;
    
    public function deleteMenu(int $menuId): bool;
    
    public function getMenu(int $menuId, bool $withItems = true): ?array;
    
    public function getMenuByLocation(string $location): ?array;
    
    public function getAllMenus(): Collection;
}
