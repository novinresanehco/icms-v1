<?php

namespace App\Repositories\Contracts;

use App\Models\Content;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ContentRepositoryInterface
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function find(int $id): ?Content;
    public function findBySlug(string $slug): ?Content;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function getPublished(): Collection;
    public function getDrafts(): Collection;
    public function search(string $query): Collection;
    public function findByType(string $type): Collection;
    public function getByCategory(int $categoryId): Collection;
    public function getByTags(array $tags): Collection;
    public function createVersion(int $contentId, array $data): bool;
    public function getVersions(int $contentId): Collection;
    public function revertToVersion(int $contentId, int $versionId): Content;
}
