<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContentRepositoryInterface
{
    public function findById(int $id): ?Content;
    public function findBySlug(string $slug): ?Content;
    public function findByType(string $type, array $options = []): LengthAwarePaginator;
    public function getPublished(array $options = []): LengthAwarePaginator;
    public function store(array $data): Content;
    public function update(int $id, array $data): ?Content;
    public function delete(int $id): bool;
    public function publish(int $id): bool;
    public function unpublish(int $id): bool;
    public function getVersions(int $id): Collection;
    public function revertToVersion(int $id, int $versionId): ?Content;
}
