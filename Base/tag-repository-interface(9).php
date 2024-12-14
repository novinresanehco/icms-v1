<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

interface TagRepositoryInterface extends RepositoryInterface
{
    public function findBySlug(string $slug): ?Tag;
    
    public function getPopularTags(int $limit = 10): Collection;
    
    public function findOrCreate(string $name, ?string $slug = null): Tag;
    
    public function syncTags(int $contentId, array $tags): void;
    
    public function getRelatedTags(int $tagId, int $limit = 5): Collection;
    
    public function searchTags(string $query): Collection;
}
