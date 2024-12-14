<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Content;
use Illuminate\Database\Eloquent\Collection;

interface ContentRepositoryInterface extends RepositoryInterface
{
    public function findPublished(array $columns = ['*']): Collection;
    
    public function findBySlug(string $slug): ?Content;
    
    public function findByCategory(int $categoryId, array $columns = ['*']): Collection;
    
    public function updateStatus(int $id, string $status): bool;
    
    public function searchContent(string $query): Collection;
    
    public function getContentVersions(int $contentId): Collection;
}
