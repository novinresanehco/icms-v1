<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Tag;
use Illuminate\Support\Collection;

interface TagRepositoryInterface
{
    public function findById(int $id): ?Tag;
    public function findBySlug(string $slug): ?Tag;
    public function findByName(string $name): ?Tag;
    public function getAll(): Collection;
    public function getPopular(int $limit = 10): Collection;
    public function store(array $data): Tag;
    public function update(int $id, array $data): ?Tag;
    public function delete(int $id): bool;
    public function syncTags(array $tags): Collection;
    public function getRelated(int $tagId, int $limit = 5): Collection;
}
