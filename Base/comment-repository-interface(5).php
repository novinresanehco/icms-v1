<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Comment;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CommentRepositoryInterface
{
    public function find(int $id): ?Comment;
    
    public function getByContent(int $contentId, array $filters = []): LengthAwarePaginator;
    
    public function create(array $data): Comment;
    
    public function update(int $id, array $data): Comment;
    
    public function delete(int $id): bool;
    
    public function getChildren(int $parentId): Collection;
    
    public function updateStatus(int $id, string $status): bool;
    
    public function getUserComments(int $userId): LengthAwarePaginator;
    
    public function getPendingComments(): LengthAwarePaginator;
    
    public function getRecentComments(int $limit = 10): Collection;
    
    public function markAsSpam(int $id): bool;
}
