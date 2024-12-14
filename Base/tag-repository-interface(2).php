<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface TagRepositoryInterface
{
    public function createTag(array $data): ?int;
    
    public function updateTag(int $tagId, array $data): bool;
    
    public function deleteTag(int $tagId): bool;
    
    public function attachTags(string $taggableType, int $taggableId, array $tagIds): bool;
    
    public function detachTags(string $taggableType, int $taggableId, ?array $tagIds = null): bool;
    
    public function getTag(int $tagId): ?array;
    
    public function getTagBySlug(string $slug): ?array;
    
    public function getAllTags(): Collection;
    
    public function getTagsByType(string $type): Collection;
    
    public function getItemTags(string $taggableType, int $taggableId): Collection;
    
    public function getPopularTags(int $limit = 10): Collection;
}
