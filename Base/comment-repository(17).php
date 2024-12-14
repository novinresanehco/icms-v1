<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommentRepository implements CommentRepositoryInterface
{
    protected $model;

    public function __construct(Comment $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->with(['user', 'content', 'parent'])->findOrFail($id);
    }

    public function getAll(array $filters = []): LengthAwarePaginator
    {
        return $this->model->with(['user', 'content'])
            ->when(isset($filters['status']), function ($query) use ($filters) {
                return $query->where('status', $filters['status']);
            })
            ->when(isset($filters['search']), function ($query) use ($filters) {
                return $query->where('content', 'like', "%{$filters['search']}%");
            })
            ->when(isset($filters['user_id']), function ($query) use ($filters) {
                return $query->where('user_id', $filters['user_id']);
            })
            ->when(isset($filters['content_id']), function ($query) use ($filters) {
                return $query->where('content_id', $filters['content_id']);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $comment = $this->model->create($data);
            return $comment->fresh(['user', 'content', 'parent']);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $comment = $this->find($id);
            $comment->update($data);
            return $comment->fresh(['user', 'content', 'parent']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $comment = $this->find($id);
            
            // Handle nested comments
            if ($comment->parent_id === null) {
                // If this is a parent comment, delete all replies
                $comment->replies()->delete();
            }
            
            return $comment->delete();
        });
    }

    public function getByContent(int $contentId): Collection
    {
        return $this->model->with(['user', 'replies.user'])
            ->where('content_id', $contentId)
            ->whereNull('parent_id')
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getByUser(int $userId): Collection
    {
        return $this->model->with(['content'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function approve(int $id)
    {
        return DB::transaction(function () use ($id) {
            $comment = $this->find($id);
            $comment->update(['status' => 'approved']);
            return $comment->fresh();
        });
    }

    public function reject(int $id)
    {
        return DB::transaction(function () use ($id) {
            $comment = $this->find($id);
            $comment->update(['status' => 'rejected']);
            return $comment->fresh();
        });
    }

    public function spam(int $id)
    {
        return DB::transaction(function () use ($id) {
            $comment = $this->find($id);
            $comment->update(['status' => 'spam']);
            return $comment->fresh();
        });
    }

    public function getAwaitingModeration(): Collection
    {
        return $this->model->with(['user', 'content'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getReplies(int $commentId): Collection
    {
        return $this->model->with(['user'])
            ->where('parent_id', $commentId)
            ->where('status', 'approved')
            ->orderBy('created_at', 'asc')
            ->get();
    }
}
