<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Module;
use Illuminate\Support\Collection;

interface ModuleRepositoryInterface
{
    public function findById(int $id): ?Module;
    public function findBySlug(string $slug): ?Module;
    public function getActive(): Collection;
    public function store(array $data): Module;
    public function update(int $id, array $data): ?Module;
    public function delete(int $id): bool;
    public function activate(int $id): bool;
    public function deactivate(int $id): bool;
}
