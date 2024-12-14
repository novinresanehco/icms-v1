<?php

namespace App\Repositories\Contracts;

use App\Models\Comment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CommentRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get comments for content
     *
     * @param string $contentType
     * @param int $contentId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getForContent(string $contentType, int $contentId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Get pending comments
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPendingComments(int $perPage = 15): LengthAwarePaginator;

    /**
     * Get comment statistics
     *
     * @return array
     */
    public function getCommentStats(): array;

    /**
     * Update comment status
     *
     * @param int $id
     * @param string $status
     * @param string|null $moderation_note
     * @return bool
     */
    public function updateStatus(int $id, string $status, ?string $moderation_note = null): bool;

    /**
     * Bulk update comment status
     *
     * @param array $commentIds
     * @param string $status
     * @param string|null $moderation_note
     * @return bool
     */
    public function bulkUpdateStatus(array $commentIds, string $status, ?string $moderation_note = null): bool;

    /**
     * Get user's recent comments
     *
     * @param int $userId
     * @param int $limit
     * @return LengthAwarePaginator
     */
    public function getUserComments(int $userId, int $limit = 10): LengthAwarePaginator;

    /**
     * Get replies for a comment
     *
     * @param int $commentId
     * @return LengthAwarePaginator
     */
    public function getReplies(int $commentId): LengthAwarePaginator;

    /**
     * Add reply to comment
     *
     * @param int $parentId
     * @param array $data
     * @return Comment|null
     */
    public function addReply(int $parentId, array $data): ?Comment;

    /**
     * Mark comment as spam
     *
     * @param int $id
     * @return bool
     */
    public function markAsSpam(int $id): bool;

    /**
     * Advanced search for comments
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator;
}
