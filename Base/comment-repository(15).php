<?php

namespace App\Core\Repositories;

use App\Models\Comment;
use Illuminate\Support\Collection;

class CommentRepository extends AdvancedRepository
{
    protected $model = Comment::class;

    public function addComment(
        string $model, 
        int $modelId, 
        string $content, 
        int $userId,
        ?int $parentId = null
    ): Comment {
        return $this->executeTransaction(function() use ($model, $modelId, $content, $userId, $parentId) {
            $comment = $this->create([
                'commentable_type' => $model,
                'commentable_id' => $modelId,
                'content' => $content,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'status' => 'pending'
            ]);

            $this->invalidateCache('getComments', $model, $modelId);
            return $comment;
        });
    }

    public function getComments(string $model, int $modelId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId) {
            return $this->model
                ->where('commentable_type', $model)
                ->where('commentable_id', $modelId)
                ->whereNull('parent_id')
                ->with(['replies', 'user'])
                ->orderBy('created_at', 'desc')
                ->get();
        }, $model, $modelId);
    }

    public function updateStatus(int $commentId, string $status): bool
    {
        return $this->executeTransaction(function() use ($commentId, $status) {
            $comment = $this->findOrFail($commentId);
            $comment->status = $status;
            $comment->save();

            $this->invalidateCache('getComments', $comment->commentable_type, $comment->commentable_id);
            return true;
        });
    }

    public function getRecentComments(int $limit = 10, string $status = 'approved'): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($limit, $status) {
            return $this->model
                ->where('status', $status)
                ->with(['commentable', 'user'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        }, $limit, $status);
    }

    public function getCommentCount(string $model, int $modelId): int
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId) {
            return $this->model
                ->where('commentable_type', $model)
                ->where('commentable_id', $modelId)
                ->where('status', 'approved')
                ->count();
        }, $model, $modelId);
    }
}
