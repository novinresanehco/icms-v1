<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CommentRepositoryInterface extends RepositoryInterface
{
    public function getContentComments(int $contentId, int $perPage = 15): LengthAwarePaginator;
    
    public function getPendingComments(): Collection;
    
    public function updateStatus(int $id, string $status): bool;
    
    public function getRecentComments(int $limit = 10): Collection;
    
    public function addReply(int $parentId, array $data): Comment;
    
    public function getUserComments(int $userId): Collection;
    
    public function markAsSpam(int $id): bool;
}
