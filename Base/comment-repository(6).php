<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommentRepository implements CommentRepositoryInterface
{
    protected Comment $model;
    
    public function __construct(Comment $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $comment = $this->model->create([
                'content_id' => $data['content_id'],
                'user_id' => $data['user_id'] ?? null,
                'content' => $data['content'],
                'approved' => $data['approved'] ?? false,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
            
            DB::commit();
            $this->clearCommentCache($comment->content_id);
            
            return $comment->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create comment: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $commentId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $comment = $this->model->findOrFail($commentId);
            $comment->update([
                'content' => $data['content'],
            ]);
            
            DB::commit();
            $this->clearCommentCache($comment->content_id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update comment: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $commentId): bool
    {
        try {
            DB::beginTransaction();
            
            $comment = $this->model->findOrFail($commentId);
            $comment->delete();
            
            DB::commit();
            $this->clearCommentCache($comment->content_id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete comment: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $commentId): ?array
    {
        try {
            $comment = $this->model->with(['user', 'approvedReplies'])->find($commentId);
            return $comment ? $comment->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get comment: ' . $e->getMessage());
            return null;
        }
    }

    public function getForContent(int $contentId, int $perPage = 15): LengthAwarePaginator
    {
        try {
            return $this->model
                ->where('content_id', $contentId)
                ->whereNull('parent_id')
                ->where('approved', true)
                ->where('is_spam', false)
                ->with(['user', 'approvedReplies.user'])
                ->latest()
                ->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get content comments: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getRecent(int $limit = 10): Collection
    {
        return Cache::remember("comments.recent.{$limit}", 3600, function() use ($limit) {
            try {
                return $this->model
                    ->where('approved', true)
                    ->where('is_spam', false)
                    ->with(['content', 'user'])
                    ->latest()
                    ->limit($limit)
                    ->get();
            } catch (\Exception $e) {
                Log::error('Failed to get recent comments: ' . $e->getMessage());
                return collect();
            }
        });
    }

    public function approve(int $commentId): bool
    {
        try {
            DB::beginTransaction();
            
            $comment = $this->model->findOrFail($commentId);
            $comment->update([
                'approved' => true,
                'is_spam' => false
            ]);
            
            DB::commit();
            $this->clearCommentCache($comment->content_id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve comment: ' . $e->getMessage());
            return false;
        }
    }

    public function reject(int $commentId): bool
    {
        try {
            DB::beginTransaction();
            
            $comment = $this->model->findOrFail($commentId);
            $comment->delete();
            
            DB::commit();
            $this->clearCommentCache($comment->content_id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject comment: ' . $e->getMessage());
            return false;
        }
    }

    public function markAsSpam(int $commentId): bool
    {
        try {
            DB::beginTransaction();
            
            $comment = $this->model->findOrFail($commentId);
            $comment->update([
                'is_spam' => true,
                'approved' => false
            ]);
            
            DB::commit();
            $this->clearCommentCache($comment->content_id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to mark comment as spam: ' . $e->getMessage());
            return false;
        }
    }

    public function getUnapproved(int $perPage = 15): LengthAwarePaginator
    {
        try {
            return $this->model
                ->where('approved', false)
                ->where('is_spam', false)
                ->with(['content', 'user'])
                ->latest()
                ->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get unapproved comments: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getSpam(int $perPage = 15): LengthAwarePaginator
    {
        try {
            return $this->model
                ->where('is_spam', true)
                ->with(['content', 'user'])
                ->latest()
                ->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get spam comments: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function replyTo(int $parentId, array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $parent = $this->model->findOrFail($parentId);
            
            $comment = $this->model->create([
                'content_id' => $parent->content_id,
                'user_id' => $data['user_id'] ?? null,
                'parent_id' => $parent->id,
                'content' => $data['content'],
                'approved' => $data['approved'] ?? false,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
            
            DB::commit();
            $this->clearCommentCache($parent->content_id);
            
            return $comment->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create reply: ' . $e->getMessage());
            return null;
        }
    }

    protected function clearCommentCache(int $contentId): void
    {
        Cache::tags(['comments', "content.{$contentId}"])->flush();
    }
}
