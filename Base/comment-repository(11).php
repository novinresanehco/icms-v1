<?php

namespace App\Core\Repositories;

use App\Core\Models\Comment;
use App\Core\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CommentRepository implements CommentRepositoryInterface
{
    public function __construct(
        private Comment $model
    ) {}

    public function findById(int $id): ?Comment
    {
        return $this->model
            ->with(['user', 'commentable'])
            ->find($id);
    }

    public function getForModel(string $modelType, int $modelId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['user', 'replies.user'])
            ->where('commentable_type', $modelType)
            ->where('commentable_id', $modelId)
            ->whereNull('parent_id')
            ->approved()
            ->latest()
            ->paginate($perPage);
    }

    public function getLatest(int $limit = 10): Collection
    {
        return $this->model
            ->with(['user', 'commentable'])
            ->approved()
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getPending(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['user', 'commentable'])
            ->pending()
            ->latest()
            ->paginate($perPage);
    }

    public function getByUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['commentable'])
            ->where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    public function store(array $data): Comment
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Comment
    {
        $comment = $this->model->findOrFail($id);
        $comment->update($data);
        return $comment->fresh();
    }

    public function approve(int $id): bool
    {
        return $this->model->findOrFail($id)->update([
            'status' => 'approved',
            'approved_at' => now()
        ]);
    }

    public function reject(int $id): bool
    {
        return $this->model->findOrFail($id)->update([
            'status' => 'rejected',
            'rejected_at' => now()
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }
}
