<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Collection;

interface MenuRepositoryInterface extends RepositoryInterface
{
    public function getMenuTree(string $location): Collection;
    
    public function updateMenuItem(int $id, array $data): bool;
    
    public function reorderMenuItems(array $order): bool;
    
    public function getActiveMenus(): Collection;
    
    public function createMenuItem(array $data): Menu;
}
