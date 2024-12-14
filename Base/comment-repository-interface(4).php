<?php

namespace App\Repositories\Contracts;

use App\Models\Comment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CommentRepositoryInterface
{
    public function findWithReplies(int $id): ?Comment;
    public function getContentComments(int $contentId, string $status = 'approved'): Collection;
    public function createComment(array $data): Comment;
    public function replyToComment(int $parentId, array $data): Comment;
    public function approveComment(int $id): bool;
    public function rejectComment(int $id): bool;
    public function markAsSpam(int $id): bool;
    public function getPendingComments(): Collection;
    public function getSpamComments(): Collection;
    public function getRecentComments(int $limit = 10): Collection;
    public function getUserComments(int $userId): Collection;
    public function getCommentsByStatus(string $status): LengthAwarePaginator;
    public function getCommentThread(int $rootCommentId): Collection;
}
