<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Support\Collection;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    protected array $searchableFields = ['content'];
    protected array $filterableFields = ['status', 'user_id', 'parent_id'];
    protected array $relationships = ['user', 'children'];

    public function __construct(Comment $model)
    {
        parent::__construct($model);
    }

    public function getByContent(int $contentId): Collection
    {
        return Cache::remember(
            $this->getCacheKey("content.{$contentId}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('content_id', $contentId)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function approve(int $id): Comment
    {
        $comment = $this->findOrFail($id);
        $comment->update(['status' => 'approved']);
        $this->clearModelCache();
        return $comment;
    }

    public function reject(int $id): Comment
    {
        $comment = $this->findOrFail($id);
        $comment->update(['status' => 'rejected']);
        $this->clearModelCache();
        return $comment;
    }

    public function getPending(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('pending'),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('status', 'pending')
                ->orderBy('created_at', 'asc')
                ->get()
        );
    }
}
