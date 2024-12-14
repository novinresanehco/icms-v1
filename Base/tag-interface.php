<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface TagRepositoryInterface
{
    public function create(array $data): Model;
    public function createMultiple(array $tags): Collection;
    public function update(int $id, array $data): Model;
    public function findById(int $id): Model;
    public function findBySlug(string $slug): Model;
    public function findByType(string $type): Collection;
    public function getFeatured(): Collection;
    public function getPopular(int $limit = 10): Collection;
    public function search(string $term): Collection;
    public function delete(int $id): bool;
    public function syncContentTags(int $contentId, array $tagIds): void;
    public function mergeTags(int $sourceId, int $targetId): Model;
}
