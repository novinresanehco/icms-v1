<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CommentRepositoryInterface
{
    public function createComment(array $data): ?int;
    
    public function updateComment(int $commentId, array $data): bool;
    
    public function deleteComment(int $commentId): bool;
    
    public function getComment(int $commentId): ?array;
    
    public function getItemComments(string $commentableType, int $commentableId, string $status = 'approved'): Collection;
    
    public function getPaginatedComments(array $filters = [], int $perPage = 20): LengthAwarePaginator;
    
    public function getRecentComments(int $limit = 10, string $status = 'approved'): Collection;
    
    public function getCommentReplies(int $commentId): Collection;
    
    public function updateStatus(int $commentId, string $status): bool;
    
    public function getCommentCount(string $commentableType, int $commentableId, string $status = 'approved'): int;
}
