<?php

namespace App\Core\Interfaces;

use App\Core\Models\Content;

interface ContentRepositoryInterface
{
    public function find(int $id, array $relations = []): ?Content;
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function findBySlug(string $slug): ?Content;
    public function search(array $criteria, array $relations = []): array;
    public function findByCategory(int $categoryId, array $relations = []): array;
    public function findByTag(int $tagId, array $relations = []): array;
}
