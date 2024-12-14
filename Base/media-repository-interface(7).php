<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Media;
use Illuminate\Support\Collection;

interface MediaRepositoryInterface
{
    public function findById(int $id): ?Media;
    public function store(array $data): Media;
    public function update(int $id, array $data): ?Media;
    public function delete(int $id): bool;
    public function findByType(string $type): Collection;
}
