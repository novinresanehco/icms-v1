<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    protected array $searchableFields = ['content', 'author_name', 'author_email'];
    protected array $filterableFields = ['status', 'content_id'];

    public function getForContent(int $contentId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->where('content_id', $contentId)
            ->where('status', 'approved')
            ->whereNull('parent_id')
            ->with('replies')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getPending(): Collection
    {
        return $this->model
            ->where('status', 'pending')
            ->with(['content', 'author'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function approve(int $id): bool
    {
        return $this->update($id, [
            'status' => 'approved',
            'approved_at' => now()
        ]);
    }

    public function markAsSpam(int $id): bool
    {
        return $this->update($id, [
            'status' => 'spam',
            'marked_as_spam_at' => now()
        ]);
    }

    public function getCommentStats(): array
    {
        return [
            'total' => $this->model->count(),
            'pending' => $this->model->where('status', 'pending')->count(),
            'approved' => $this->model->where('status', 'approved')->count(),
            'spam' => $this->model->where('status', 'spam')->count(),
            'by_content' => $this->model
                ->groupBy('content_id')
                ->selectRaw('content_id, count(*) as count')
                ->get()
                ->pluck('count', 'content_id')
                ->toArray()
        ];
    }
}
