<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class CommentRepository extends BaseRepository
{
    public function __construct(Comment $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByContent(int $contentId): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$contentId], function () use ($contentId) {
            return $this->model->where('content_id', $contentId)
                             ->where('status', 'approved')
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function findPending(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('status', 'pending')
                             ->orderBy('created_at', 'asc')
                             ->get();
        });
    }

    public function approve(int $id): bool
    {
        $result = $this->update($id, [
            'status' => 'approved',
            'approved_at' => now()
        ]);
        
        $this->clearCache();
        return $result;
    }

    public function reject(int $id): bool
    {
        $result = $this->update($id, [
            'status' => 'rejected',
            'rejected_at' => now()
        ]);
        
        $this->clearCache();
        return $result;
    }

    public function findByUser(int $userId): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$userId], function () use ($userId) {
            return $this->model->where('user_id', $userId)
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }
}
