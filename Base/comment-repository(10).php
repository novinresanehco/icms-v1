<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CommentRepositoryInterface;
use App\Models\Comment;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CommentRepository implements CommentRepositoryInterface
{
    protected Comment $model;

    public function __construct(Comment $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Comment
    {
        return $this->model->find($id);
    }

    public function getByContent(int $contentId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->where('content_id', $contentId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['parent_id'])) {
            $query->where('parent_id', $filters['parent_id']);
        } else {
            $query->whereNull('parent_id');
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Comment
    {
        DB::beginTransaction();
        try {
            $comment = $this->model->create($data);
            
            if (!empty($data['meta'])) {
                $comment->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $comment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Comment
    {
        DB::beginTransaction();
        try {
            $comment = $this->model->findOrFail($id);
            $comment->update($data);
            
            if (isset($data['meta'])) {
                $comment->meta()->delete();
                $comment->meta()->createMany($data['meta']);
            }
            
            DB::commit();
            return $comment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $comment = $this->model->findOrFail($id);
            $comment->meta()->delete();
            $comment->delete();
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getChildren(int $parentId): Collection
    {
        return $this->model->where('parent_id', $parentId)
            ->where('status', 'approved')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function updateStatus(int $id, string $status): bool
    {
        return (bool) $this->model->where('id', $id)->update(['status' => $status]);
    }

    public function getUserComments(int $userId): LengthAwarePaginator
    {
        return $this->model->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    public function getPendingComments(): LengthAwarePaginator
    {
        return $this->model->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate(15);
    }

    public function getRecentComments(int $limit = 10): Collection
    {
        return $this->model->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function markAsSpam(int $id): bool
    {
        return (bool) $this->model->where('id', $id)
            ->update([
                'status' => 'spam',
                'spam_marked_at' => now()
            ]);
    }
}
