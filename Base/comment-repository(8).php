<?php

namespace App\Repositories;

use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CommentRepository implements CommentRepositoryInterface
{
    protected string $table = 'comments';

    public function createComment(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $commentId = DB::table($this->table)->insertGetId([
                'content' => $data['content'],
                'user_id' => $data['user_id'],
                'commentable_type' => $data['commentable_type'],
                'commentable_id' => $data['commentable_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->clearCommentCache($data['commentable_type'], $data['commentable_id']);
            DB::commit();

            return $commentId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create comment: ' . $e->getMessage());
            return null;
        }
    }

    public function updateComment(int $commentId, array $data): bool
    {
        try {
            $comment = $this->getComment($commentId);
            if (!$comment) {
                return false;
            }

            $updated = DB::table($this->table)
                ->where('id', $commentId)
                ->update(array_merge($data, [
                    'updated_at' => now()
                ])) > 0;

            if ($updated) {
                $this->clearCommentCache($comment['commentable_type'], $comment['commentable_id']);
            }

            return $updated;
        } catch (\Exception $e) {
            \Log::error('Failed to update comment: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteComment(int $commentId): bool
    {
        try {
            DB::beginTransaction();

            $comment = $this->getComment($commentId);
            if (!$comment) {
                return false;
            }

            // Update children comments to point to parent's parent
            DB::table($this->table)
                ->where('parent_id', $commentId)
                ->update(['parent_id' => $comment['parent_id']]);

            // Delete comment
            $deleted = DB::table($this->table)
                ->where('id', $commentId)
                ->delete() > 0;

            if ($deleted) {
                $this->clearCommentCache($comment['commentable_type'], $comment['commentable_id']);
            }

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete comment: ' . $e->getMessage());
            return false;
        }
    }

    public function getComment(int $commentId): ?array
    {
        try {
            $comment = DB::table($this->table)
                ->where('id', $commentId)
                ->first();

            return $comment ? (array) $comment : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get comment: ' . $e->getMessage());
            return null;
        }
    }

    public function getItemComments(
        string $commentableType, 
        int $commentableId, 
        string $status = 'approved'
    ): Collection {
        $cacheKey = "item_comments_{$commentableType}_{$commentableId}_{$status}";

        return Cache::remember($cacheKey, 3600, function() use ($commentableType, $commentableId, $status) {
            return collect(DB::table($this->table)
                ->where('commentable_type', $commentableType)
                ->where('commentable_id', $commentableId)
                ->where('status', $status)
                ->orderBy('created_at', 'desc')
                ->get());
        });
    }

    public function getPaginatedComments(
        array $filters = [], 
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = DB::table($this->table);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['commentable_type'])) {
            $query->where('commentable_type', $filters['commentable_type']);
        }

        if (!empty($filters['commentable_id'])) {
            $query->where('commentable_id', $filters['commentable_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('content', 'like', "%{$filters['search']}%");
        }

        return $query->orderBy('created_at', 'desc')
                    ->paginate($perPage);
    }

    public function getRecentComments(int $limit = 10, string $status = 'approved'): Collection
    {
        return Cache::remember("recent_comments_{$limit}_{$status}", 3600, function() use ($limit, $status) {
            return collect(DB::table($this->table)
                ->where('status', $status)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get());
        });
    }

    public function getCommentReplies(int $commentId): Collection
    {
        return collect(DB::table($this->table)
            ->where('parent_id', $commentId)
            ->where('status', 'approved')
            ->orderBy('created_at', 'asc')
            ->get());
    }

    public function updateStatus(int $commentId, string $status): bool
    {
        try {
            $comment = $this->getComment($commentId);
            if (!$comment) {
                return false;
            }

            $updated = DB::table($this->table)
                ->where('id', $commentId)
                ->update([
                    'status' => $status,
                    'updated_at' => now()
                ]) > 0;

            if ($updated) {
                $this->clearCommentCache($comment['commentable_type'], $comment['commentable_id']);
            }

            return $updated;
        } catch (\Exception $e) {
            \Log::error('Failed to update comment status: ' . $e->getMessage());
            return false;
        }
    }

    public function getCommentCount(
        string $commentableType, 
        int $commentableId, 
        string $status = 'approved'
    ): int {
        $cacheKey = "comment_count_{$commentableType}_{$commentableId}_{$status}";

        return Cache::remember($cacheKey, 3600, function() use ($commentableType, $commentableId, $status) {
            return DB::table($this->table)
                ->where('commentable_type', $commentableType)
                ->where('commentable_id', $commentableId)
                ->where('status', $status)
                ->count();
        });
    }

    protected function clearCommentCache(string $commentableType, int $commentableId): void
    {
        $statuses = ['pending', 'approved', 'spam', 'trash'];
        
        foreach ($statuses as $status) {
            Cache::forget("item_comments_{$commentableType}_{$commentableId}_{$status}");
            Cache::forget("comment_count_{$commentableType}_{$commentableId}_{$status}");
            Cache::forget("recent_comments_10_{$status}");
        }

        Cache::tags(['comments'])->flush();
    }
}
