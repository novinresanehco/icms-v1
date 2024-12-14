<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    protected array $searchableFields = ['content', 'author_name', 'author_email'];
    protected array $filterableFields = ['status', 'content_type', 'content_id'];

    /**
     * Get comments for content
     *
     * @param string $contentType
     * @param int $contentId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getForContent(string $contentType, int $contentId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('status', 'approved')
            ->whereNull('parent_id')
            ->with(['replies' => function($query) {
                $query->where('status', 'approved')
                    ->orderBy('created_at');
            }])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Get pending comments
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPendingComments(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('status', 'pending')
            ->with(['content'])
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    /**
     * Get comment statistics
     *
     * @return array
     */
    public function getCommentStats(): array
    {
        $cacheKey = 'comment.stats';

        return Cache::tags(['comments'])->remember($cacheKey, 300, function() {
            return [
                'total' => $this->model->count(),
                'pending' => $this->model->where('status', 'pending')->count(),
                'approved' => $this->model->where('status', 'approved')->count(),
                'spam' => $this->model->where('status', 'spam')->count(),
                'by_content_type' => $this->model
                    ->groupBy('content_type')
                    ->selectRaw('content_type, count(*) as count')
                    ->pluck('count', 'content_type')
                    ->toArray(),
                'recent_activity' => $this->model
                    ->whereIn('status', ['approved', 'pending'])
                    ->orderByDesc('created_at')
                    ->take(5)
                    ->get()
            ];
        });
    }

    /**
     * Update comment status
     *
     * @param int $id
     * @param string $status
     * @param string|null $moderation_note
     * @return bool
     */
    public function updateStatus(int $id, string $status, ?string $moderation_note = null): bool
    {
        try {
            return (bool) $this->update($id, [
                'status' => $status,
                'moderation_note' => $moderation_note,
                'moderated_at' => now(),
                'moderator_id' => auth()->id()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating comment status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk update comment status
     *
     * @param array $commentIds
     * @param string $status
     * @param string|null $moderation_note
     * @return bool
     */
    public function bulkUpdateStatus(array $commentIds, string $status, ?string $moderation_note = null): bool
    {
        try {
            DB::beginTransaction();

            $this->model->whereIn('id', $commentIds)
                ->update([
                    'status' => $status,
                    'moderation_note' => $moderation_note,
                    'moderated_at' => now(),
                    'moderator_id' => auth()->id()
                ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error bulk updating comment status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user's recent comments
     *
     * @param int $userId
     * @param int $limit
     * @return LengthAwarePaginator
     */
    public function getUserComments(int $userId, int $limit = 10): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($limit);
    }

    /**
     * Get replies for a comment
     *
     * @param int $commentId
     * @return LengthAwarePaginator
     */
    public function getReplies(int $commentId): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('parent_id', $commentId)
            ->where('status', 'approved')
            ->orderBy('created_at')
            ->paginate(10);
    }

    /**
     * Add reply to comment
     *
     * @param int $parentId
     * @param array $data
     * @return Comment|null
     */
    public function addReply(int $parentId, array $data): ?Comment
    {
        try {
            $parentComment = $this->find($parentId);
            if (!$parentComment) {
                return null;
            }

            $data['parent_id'] = $parentId;
            $data['content_type'] = $parentComment->content_type;
            $data['content_id'] = $parentComment->content_id;
            $data['status'] = config('comments.auto_approve') ? 'approved' : 'pending';

            return $this->create($data);
        } catch (\Exception $e) {
            \Log::error('Error adding reply to comment: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark comment as spam
     *
     * @param int $id
     * @return bool
     */
    public function markAsSpam(int $id): bool
    {
        try {
            return (bool) $this->update($id, [
                'status' => 'spam',
                'moderated_at' => now(),
                'moderator_id' => auth()->id()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error marking comment as spam: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Advanced search for comments
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function($q) use ($searchTerm) {
                foreach ($this->searchableFields as $field) {
                    $q->orWhere($field, 'like', "%{$searchTerm}%");
                }
            });
        }

        foreach ($this->filterableFields as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['has_replies'])) {
            $query->has('replies', $filters['has_replies'] ? '>' : '=', 0);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
