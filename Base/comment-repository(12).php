<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\CommentRepositoryInterface;
use App\Core\Models\Comment;
use App\Core\Exceptions\CommentRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Carbon;

class CommentRepository implements CommentRepositoryInterface
{
    protected Comment $model;
    protected const CACHE_PREFIX = 'comment:';
    protected const CACHE_TTL = 1800;

    public function __construct(Comment $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $comment = $this->model->create([
                'content_id' => $data['content_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'author_name' => $data['author_name'] ?? null,
                'author_email' => $data['author_email'] ?? null,
                'content' => $data['content'],
                'status' => $data['status'] ?? 'pending',
                'ip_address' => $data['ip_address'] ?? request()->ip(),
                'user_agent' => $data['user_agent'] ?? request()->userAgent()
            ]);

            DB::commit();
            $this->clearCache($comment->content_id);

            return $comment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentRepositoryException("Failed to create comment: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $comment = $this->findById($id);
            
            $comment->update([
                'content' => $data['content'] ?? $comment->content,
                'status' => $data['status'] ?? $comment->status,
                'edited_at' => Carbon::now(),
                'edited_by' => $data['edited_by'] ?? null
            ]);

            DB::commit();
            $this->clearCache($comment->content_id);

            return $comment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentRepositoryException("Failed to update comment: {$e->getMessage()}", 0, $e);
        }
    }

    public function updateStatus(int $id, string $status): Model
    {
        try {
            DB::beginTransaction();

            $comment = $this->findById($id);
            $comment->update(['status' => $status]);

            DB::commit();
            $this->clearCache($comment->content_id);

            return $comment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentRepositoryException("Failed to update comment status: {$e->getMessage()}", 0, $e);
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with(['user', 'content', 'replies'])->findOrFail($id)
        );
    }

    public function getContentComments(int $contentId, bool $threaded = true): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "content:{$contentId}:" . ($threaded ? 'threaded' : 'flat'),
            self::CACHE_TTL,
            function () use ($contentId, $threaded) {
                $query = $this->model->where('content_id', $contentId)
                    ->where('status', 'approved')
                    ->with(['user', 'replies']);

                if ($threaded) {
                    $query->whereNull('parent_id');
                }

                return $query->orderBy('created_at', 'desc')->get();
            }
        );
    }

    public function getPendingComments(): Collection
    {
        return $this->model->where('status', 'pending')
            ->with(['user', 'content'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getRecentComments(int $limit = 10): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "recent:{$limit}",
            self::CACHE_TTL,
            fn () => $this->model->where('status', 'approved')
                ->with(['user', 'content'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function getUserComments(int $userId): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "user:{$userId}",
            self::CACHE_TTL,
            fn () => $this->model->where('user_id', $userId)
                ->with(['content'])
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function searchComments(array $criteria): Collection
    {
        $query = $this->model->newQuery()->with(['user', 'content']);

        if (isset($criteria['term'])) {
            $query->where('content', 'like', "%{$criteria['term']}%");
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        if (isset($criteria['start_date'])) {
            $query->where('created_at', '>=', $criteria['start_date']);
        }

        if (isset($criteria['end_date'])) {
            $query->where('created_at', '<=', $criteria['end_date']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($criteria['per_page'] ?? 15);
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $comment = $this->findById($id);
            $contentId = $comment->content_id;

            // Delete replies first
            $comment->replies()->delete();
            $deleted = $comment->delete();

            DB::commit();
            $this->clearCache($contentId);

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentRepositoryException("Failed to delete comment: {$e->getMessage()}", 0, $e);
        }
    }

    public function markAsSpam(int $id): void
    {
        try {
            DB::beginTransaction();

            $comment = $this->findById($id);
            $comment->update([
                'status' => 'spam',
                'spam_marked_at' => Carbon::now()
            ]);

            DB::commit();
            $this->clearCache($comment->content_id);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentRepositoryException("Failed to mark comment as spam: {$e->getMessage()}", 0, $e);
        }
    }

    protected function clearCache(int $contentId): void
    {
        Cache::tags(['comments', "content-{$contentId}-comments"])->flush();
    }
}
