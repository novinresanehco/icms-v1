<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\CommentRepositoryInterface;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    public function __construct(Comment $model)
    {
        parent::__construct($model);
    }

    public function getContentComments(int $contentId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->where('content_id', $contentId)
            ->where('status', 'approved')
            ->with(['user', 'replies'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getPendingComments(): Collection
    {
        return Cache::tags(['comments', 'pending'])->remember(
            'comments:pending',
            now()->addMinutes(15),
            fn () => $this->model
                ->where('status', 'pending')
                ->with(['user', 'content'])
                ->orderBy('created_at', 'asc')
                ->get()
        );
    }

    public function updateStatus(int $id, string $status): bool
    {
        $result = $this->update($id, ['status' => $status]);
        
        if ($result) {
            Cache::tags(['comments'])->flush();
        }
        
        return $result;
    }

    public function getRecentComments(int $limit = 10): Collection
    {
        return Cache::tags(['comments', 'recent'])->remember(
            "comments:recent:{$limit}",
            now()->addHours(1),
            fn () => $this->model
                ->where('status', 'approved')
                ->with(['user', 'content'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function addReply(int $parentId, array $data): Comment
    {
        $data['parent_id'] = $parentId;
        $comment = $this->create($data);
        
        Cache::tags(['comments'])->flush();
        
        return $comment;
    }

    public function getUserComments(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->with('content')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function markAsSpam(int $id): bool
    {
        $result = $this->update($id, [
            'status' => 'spam',
            'spam_marked_at' => now()
        ]);

        if ($result) {
            Cache::tags(['comments'])->flush();
        }

        return $result;
    }
}
