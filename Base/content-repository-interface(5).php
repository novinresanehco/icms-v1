<?php

namespace App\Repositories\Contracts;

use App\Models\Content;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ContentRepositoryInterface
{
    public function findBySlug(string $slug): ?Content;
    public function findWithRelations(int $id): ?Content;
    public function createWithMetadata(array $data, array $metadata): Content;
    public function updateWithMetadata(int $id, array $data, array $metadata): bool;
    public function publishContent(int $id): bool;
    public function unpublishContent(int $id): bool;
    public function getPublishedContent(): Collection;
    public function getDraftContent(): Collection;
    public function getContentByType(string $type): Collection;
    public function searchContent(string $query): Collection;
    public function getContentVersions(int $id): Collection;
    public function revertToVersion(int $contentId, int $versionId): bool;
    public function paginateByStatus(string $status, int $perPage = 15): LengthAwarePaginator;
}
