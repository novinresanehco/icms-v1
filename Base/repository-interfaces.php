<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

interface RepositoryInterface
{
    public function find(int|string $id): ?Model;
    public function findAll(): Collection;
    public function create(array $data): Model;
    public function update(Model $model, array $data): bool;
    public function delete(Model $model): bool;
}

interface CacheableRepositoryInterface extends RepositoryInterface
{
    public function getCacheKey(string $method, array $parameters = []): string;
    public function getCacheTTL(): int;
    public function clearCache(): bool;
}
