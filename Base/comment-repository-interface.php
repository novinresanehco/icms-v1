<?php

declare(strict_types=1);

namespace App\Repositories\Interfaces;

use App\Models\Comment;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CommentRepositoryInterface
{
    /**
     * Find comment by ID
     *
     * @param int $id
     * @return Comment|null
     */
    public function findById(int $id): ?Comment;

    /**
     * Create new comment
     *
     * @param array $data
     * @return Comment
     */
    public function create(array $data): Comment;

    /**
     * Update comment
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete comment
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Get comments by content
     *
     * @param int $contentId
     * @param array $options
     * @return Collection
     */
    public function getByContent(int $contentId, array $options = []): Collection;

    /**
     * Paginate comments
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Update comment status
     *
     * @param int $id
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool;

    /**
     * Get replies for comment
     *
     * @param int $commentId
     * @return Collection
     */
    public function getReplies(int $commentId): Collection;

    /**
     * Get recent comments by user
     *
     * @param int $userId
     * @param int $limit
     * @return Collection
     */
    public function getRecentByUser(int $userId, int $limit = 10): Collection;
}