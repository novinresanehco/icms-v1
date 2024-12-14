<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Support\Collection;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    protected array $searchableFields = ['content'];
    protected array $filterableFields = ['status', 'user_id', 'parent_id'];

    public function __construct(Comment $model)
    {
        parent::__construct($model);
    }

    public function getForContent(string $contentType, int $contentId): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("content.{$contentType}.{$contentId}"),
                $this->cacheTTL,
                fn() => $this->model->with(['user', 'replies'])
                    ->where('content_type', $contentType)
                    ->where('content_id', $contentId)
                    ->whereNull('parent_id')
                    ->orderByDesc('created_at')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get comments for content: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function approve(int $commentId): bool
    {
        try {
            DB::beginTransaction();

            $comment = $this->find($commentId);
            if (!$comment) {
                throw new \Exception('Comment not found');
            }

            $comment->update(['status' => 'approved']);

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve comment: ' . $e->getMessage());
            return false;
        }
    }

    public function getRecentForUser(int $userId, int $limit = 10): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("user.{$userId}.recent.{$limit}"),
                $this->cacheTTL,
                fn() => $this->model->with(['content'])
                    ->where('user_id', $userId)
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get recent user comments: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getStats(): array
    {
        try {
            return Cache::remember(
                $this->getCacheKey('stats'),
                $this->cacheTTL,
                fn() => [
                    'total' => $this->model->count(),
                    'pending' => $this->model->where('status', 'pending')->count(),
                    'approved' => $this->model->where('status', 'approved')->count(),
                    'spam' => $this->model->where('status', 'spam')->count(),
                    'recent' => $this->model->with(['user', 'content'])
                        ->orderByDesc('created_at')
                        ->limit(5)
                        ->get()
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to get comment stats: ' . $e->getMessage());
            return [];
        }
    }
}
