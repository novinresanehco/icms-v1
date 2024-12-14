<?php

namespace App\Core\Repository;

use App\Models\Comment;
use App\Core\Events\CommentEvents;
use App\Core\Exceptions\CommentRepositoryException;

class CommentRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Comment::class;
    }

    /**
     * Get comments for content
     */
    public function getContentComments(int $contentId, array $options = []): Collection
    {
        $query = $this->model->where('content_id', $contentId);

        if (isset($options['status'])) {
            $query->where('status', $options['status']);
        }

        if (isset($options['orderBy'])) {
            $query->orderBy($options['orderBy'], $options['order'] ?? 'desc');
        } else {
            $query->latest();
        }

        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("content.{$contentId}", serialize($options)),
            $this->cacheTime,
            fn() => $query->get()
        );
    }

    /**
     * Update comment status
     */
    public function updateStatus(int $id, string $status): Comment
    {
        try {
            $comment = $this->find($id);
            if (!$comment) {
                throw new CommentRepositoryException("Comment not found with ID: {$id}");
            }

            $comment->update(['status' => $status]);
            $this->clearCache();

            event(new CommentEvents\CommentStatusUpdated($comment));
            return $comment;

        } catch (\Exception $e) {
            throw new CommentRepositoryException(
                "Failed to update comment status: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get pending comments
     */
    public function getPendingComments(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('pending'),
            $this->cacheTime,
            fn() => $this->model->where('status', 'pending')
                               ->with(['content', 'user'])
                               ->latest()
                               ->get()
        );
    }

    /**
     * Get user comments
     */
    public function getUserComments(int $userId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('user', $userId),
            $this->cacheTime,
            fn() => $this->model->where('user_id', $userId)
                               ->with('content')
                               ->latest()
                               ->get()
        );
    }

    /**
     * Mark comment as spam
     */
    public function markAsSpam(int $id): void
    {
        try {
            $comment = $this->updateStatus($id, 'spam');
            event(new CommentEvents\CommentMarkedAsSpam($comment));
        } catch (\Exception $e) {
            throw new CommentRepositoryException(
                "Failed to mark comment as spam: {$e->getMessage()}"
            );
        }
    }
}
