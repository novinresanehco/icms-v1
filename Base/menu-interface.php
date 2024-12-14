<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface MenuRepositoryInterface
{
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function findById(int $id): Model;
    public function findByLocation(string $location, ?string $language = null): ?Model;
    public function getAllActive(): Collection;
    public function reorderItems(int $menuId, array $order): bool;
    public function delete(int $id): bool;
    public function cloneMenu(int $id, array $data = []): Model;
}
