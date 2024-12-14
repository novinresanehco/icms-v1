<?php

namespace App\Repositories\Contracts;

use App\Models\Tag;
use Illuminate\Support\Collection;

interface TagRepositoryInterface
{
    public function findBySlug(string $slug): ?Tag;
    public function findByName(string $name): ?Tag;
    public function getPopularTags(int $limit = 10): Collection;
    public function syncContentTags(int $contentId, array $tags): bool;
    public function createWithMetadata(string $name, array $metadata = []): Tag;
    public function mergeTags(int $sourceId, int $targetId): bool;
    public function getTagCloud(): array;
    public function getRelatedTags(int $tagId, int $limit = 5): Collection;
    public function searchTags(string $query): Collection;
    public function updateUsageCount(int $tagId): void;
}
