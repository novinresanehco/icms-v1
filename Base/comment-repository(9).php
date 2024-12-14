<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Comment();
    }

    public function findWithReplies(int $id): ?Comment
    {
        return $this->model->with(['replies' => function($query) {
            $query->where('status', 'approved')
                  ->orderBy('created_at', 'asc');
        }])->find($id);
    }

    public function getContentComments(int $contentId, string $status = 'approved'): Collection
    {
        return $this->model->where('content_id', $contentId)
            ->where('status', $status)
            ->whereNull('parent_id')
            ->with(['replies' => function($query) {
                $query->where('status', 'approved')
                      ->orderBy('created_at', 'asc');
            }])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createComment(array $data): Comment
    {
        $data['status'] = config('cms.comments.auto_approve', false) ? 'approved' : 'pending';
        
        $comment = $this->model->create($data);
        
        if ($comment->status === 'approved') {
            $this->updateCommentCounts($comment->content_id);
        }
        
        return $comment;
    }

    public function replyToComment(int $parentId, array $data): Comment
    {
        $parent = $this->model->findOrFail($parentId);
        
        $data['content_id'] = $parent->content_id;
        $data['parent_id'] = $parentId;
        $data['status'] = config('cms.comments.auto_approve', false) ? 'approved' : 'pending';
        
        $reply = $this->model->create($data);
        
        if ($reply->status === 'approved') {
            $this->updateCommentCounts($reply->content_id);
        }
        
        return $reply;
    }

    public function approveComment(int $id): bool
    {
        $comment = $this->model->findOrFail($id);
        
        if ($comment->update(['status' => 'approved'])) {
            $this->updateCommentCounts($comment->content_id);
            return true;
        }
        
        return false;
    }

    public function rejectComment(int $id): bool
    {
        $comment = $this->model->findOrFail($id);
        
        if ($comment->update(['status' => 'rejected'])) {
            $this->updateCommentCounts($comment->content_id);
            return true;
        }
        
        return false;
    }

    public function markAsSpam(int $id): bool
    {
        $comment = $this->model->findOrFail($id);
        
        if ($comment->update(['status' => 'spam'])) {
            $this->updateCommentCounts($comment->content_id);
            return true;
        }
        
        return false;
    }

    public function getPendingComments(): Collection
    {
        return $this->model->where('status', 'pending')
            ->with(['content', 'author'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getSpamComments(): Collection
    {
        return $this->model->where('status', 'spam')
            ->with(['content', 'author'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getRecentComments(int $limit = 10): Collection
    {
        return $this->model->where('status', 'approved')
            ->with(['content', 'author'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getUserComments(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->with(['content'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getCommentsByStatus(string $status): LengthAwarePaginator
    {
        return $this->model->where('status', $status)
            ->with(['content', 'author'])
            ->orderBy('created_at', 'desc')
            ->paginate(config('cms.comments.per_page', 20));
    }

    public function getCommentThread(int $rootCommentId): Collection
    {
        return $this->model->where('id', $rootCommentId)
            ->orWhere('parent_id', $rootCommentId)
            ->where('status', 'approved')
            ->with(['author'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    protected function updateCommentCounts(int $contentId): void
    {
        $approvedCount = $this->model->where('content_id', $contentId)
            ->where('status', 'approved')
            ->count();
            
        \DB::table('contents')
            ->where('id', $contentId)
            ->update(['comment_count' => $approvedCount]);
    }
}
