<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Comment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\CommentRepositoryInterface;

class CommentRepository implements CommentRepositoryInterface
{
    private const CACHE_PREFIX = 'comment:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Comment $model
    ) {}

    public function findById(int $id): ?Comment
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with(['user', 'parent'])->find($id)
        );
    }

    public function create(array $data): Comment
    {
        $comment = $this->model->create([
            'content_id' => $data['content_id'],
            'user_id' => $data['user_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'content' => $data['content'],
            'status' => $data['status'] ?? 'pending',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        $this->clearCache($comment);

        return $comment;
    }

    public function update(int $id, array $data): bool
    {
        $comment = $this->findById($id);
        
        if (!$comment) {
            return false;
        }

        $updated = $comment->update([
            'content' => $data['content'] ?? $comment->content,
            'status' => $data['status'] ?? $comment->status
        ]);

        if ($updated) {
            $this->clearCache($comment);
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $comment = $this->findById($id);
        
        if (!$comment) {
            return false;
        }

        // If comment has replies, soft delete
        if ($comment->replies()->exists()) {
            $deleted = $comment->update([
                'content' => '[Comment deleted]',
                'status' => 'deleted'
            ]);
        } else {
            $deleted = $comment->delete();
        }

        if ($deleted) {
            $this->clearCache($comment);
        }

        return $deleted;
    }

    public function getByContent(int $contentId, array $options = []): Collection
    {
        $cacheKey = self::CACHE_PREFIX . "content:{$contentId}:" . md5(serialize($options));

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($contentId, $options) {
                $query = $this->model->where('content_id', $contentId);

                if (isset($options['status'])) {
                    $query->where('status', $options['status']);
                }

                if (isset($options['parent_id'])) {
                    $query->where('parent_id', $options['parent_id']);
                } else {
                    $query->whereNull('parent_id');
                }

                return $query->with(['user', 'replies.user'])
                    ->orderBy('created_at', $options['order'] ?? 'desc')
                    ->get();
            }
        );
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->with(['user', 'content'])
            ->when(isset($filters['status']), function ($q) use ($filters) {
                $q->where('status', $filters['status']);
            })
            ->when(isset($filters['user_id']), function ($q) use ($filters) {
                $q->where('user_id', $filters['user_id']);
            })
            ->when(isset($filters['content_id']), function ($q) use ($filters) {
                $q->where('content_id', $filters['content_id']);
            })
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $q->where('content', 'like', "%{$filters['search']}%");
            });

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $comment = $this->findById($id);
        
        if (!$comment) {
            return false;
        }

        $updated = $comment->update(['status' => $status]);

        if ($updated) {
            $this->clearCache($comment);
        }

        return $updated;
    }

    public function getReplies(int $commentId): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "replies:{$commentId}",
            self::CACHE_TTL,
            fn () => $this->model->where('parent_id', $commentId)
                ->with(['user'])
                ->orderBy('created_at')
                ->get()
        );
    }

    public function getRecentByUser(int $userId, int $limit = 10): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "user:{$userId}:recent:{$limit}",
            self::CACHE_TTL,
            fn () => $this->model->where('user_id', $userId)
                ->with(['content'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    protected function clearCache(Comment $comment): void
    {
        Cache::forget(self::CACHE_PREFIX . $comment->id);
        Cache::forget(self::CACHE_PREFIX . "content:{$comment->content_id}");
        
        if ($comment->parent_id) {
            Cache::forget(self::CACHE_PREFIX . "replies:{$comment->parent_id}");
        }
        
        Cache::forget(self::CACHE_PREFIX . "user:{$comment->user_id}:recent:10");
    }
}