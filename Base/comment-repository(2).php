<?php

namespace App\Repositories;

use App\Models\Comment;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class CommentRepository extends BaseRepository implements CommentRepositoryInterface
{
    protected array $searchableFields = ['content'];
    protected array $filterableFields = ['status', 'user_id', 'parent_id'];

    public function getForContent(string $contentType, int $contentId, array $relations = []): Collection
    {
        $cacheKey = "comments.{$contentType}.{$contentId}." . md5(serialize($relations));

        return Cache::tags(['comments'])->remember($cacheKey, 3600, function() use ($contentType, $contentId, $relations) {
            return $this->model
                ->where('commentable_type', $contentType)
                ->where('commentable_id', $contentId)
                ->where('status', 'approved')
                ->whereNull('parent_id')
                ->with(['replies' => function($query) {
                    $query->where('status', 'approved')
                        ->orderBy('created_at', 'asc');
                }])
                ->with($relations)
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function create(array $data): Comment
    {
        $comment = parent::create(array_merge($data, [
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'status' => $this->getInitialStatus($data)
        ]));

        Cache::tags(['comments'])->flush();

        return $comment;
    }

    public function updateStatus(int $id, string $status): bool
    {
        try {
            $this->update($id, ['status' => $status]);
            Cache::tags(['comments'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating comment status: ' . $e->getMessage());
            return false;
        }
    }

    public function getAwaitingModeration(): Collection
    {
        return $this->model
            ->where('status', 'pending')
            ->with(['user', 'commentable'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getUserComments(int $userId): Collection
    {
        $cacheKey = 'comments.user.' . $userId;

        return Cache::tags(['comments'])->remember($cacheKey, 3600, function() use ($userId) {
            return $this->model
                ->where('user_id', $userId)
                ->with('commentable')
                ->orderByDesc('created_at')
                ->get();
        });
    }

    public function getRecentComments(int $limit = 10): Collection
    {
        $cacheKey = 'comments.recent.' . $limit;

        return Cache::tags(['comments'])->remember($cacheKey, 3600, function() use ($limit) {
            return $this->model
                ->where('status', 'approved')
                ->with(['user', 'commentable'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function markAsSpam(int $id): bool
    {
        try {
            $comment = $this->find($id);
            $comment->update([
                'status' => 'spam',
                'metadata' => array_merge(
                    $comment->metadata ?? [],
                    ['marked_as_spam_at' => now()]
                )
            ]);

            // Mark all replies as spam
            $comment->replies()->update([
                'status' => 'spam'
            ]);

            Cache::tags(['comments'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error marking comment as spam: ' . $e->getMessage());
            return false;
        }
    }

    protected function getInitialStatus(array $data): string
    {
        // Auto-approve comments from trusted users
        if (auth()->user()?->hasRole('trusted_commenter')) {
            return 'approved';
        }

        // Check if moderation is required based on content
        if ($this->requiresModeration($data['content'])) {
            return 'pending';
        }

        return config('comments.default_status', 'pending');
    }

    protected function requiresModeration(string $content): bool
    {
        // Check for spam keywords
        $spamKeywords = config('comments.spam_keywords', []);
        foreach ($spamKeywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                return true;
            }
        }

        // Check for too many links
        $maxLinks = config('comments.max_links', 2);
        if (substr_count(strtolower($content), 'http') > $maxLinks) {
            return true;
        }

        return false;
    }
}
