<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Tag;
use Illuminate\Support\Collection;

interface TagRepositoryInterface
{
    public function syncTags(array $tagNames): Collection;
    
    public function getPopular(int $limit): Collection;
    
    public function getRelated(int $tagId, int $limit): Collection;
    
    public function store(array $data): Tag;
    
    public function update(int $id, array $data): Tag;
    
    public function delete(int $id): bool;
}
