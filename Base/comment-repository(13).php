<?php

namespace App\Core\Repositories;

use App\Core\Models\Comment;
use App\Core\Events\{CommentCreated, CommentUpdated, CommentDeleted};
use App\Core\Exceptions\CommentException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\{DB, Event};

class CommentRepository extends Repository
{
    protected array $with = ['author', 'parent'];
    protected array $withCount = ['replies'];

    public function getForContent(
        Model $content,
        array $filters = [],
        int $perPage = 15
    ): Collection {
        return $this->query()
            ->where('commentable_type', get_class($content))
            ->where('commentable_id', $content->id)
            ->when(isset($filters['status']), function($query) use ($filters) {
                $query->where('status', $filters['status']);
            })
            ->when(isset($filters['parent_id']), function($query) use ($filters) {
                $query->where('parent_id', $filters['parent_id']);
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['order'] ?? 'desc')
            ->paginate($perPage);
    }

    public function createComment(Model $content, array $attributes): Model
    {
        return DB::transaction(function() use ($content, $attributes) {
            $comment = $this->create(array_merge($attributes, [
                'commentable_type' => get_class($content),
                'commentable_id' => $content->id,
                'author_id' => auth()->id(),
                'status' => 'pending'
            ]));

            Event::dispatch(new CommentCreated($comment));

            return $comment;
        });
    }

    public function updateStatus(Model $comment, string $status): bool
    {
        if (!in_array($status, ['pending', 'approved', 'rejected', 'spam'])) {
            throw new CommentException('Invalid comment status');
        }

        return $this->update($comment, [
            'status' => $status,
            'moderated_at' => now(),
            'moderated_by' => auth()->id()
        ]);
    }

    public function getReplies(Model $comment): Collection
    {
        return $this->query()
            ->where('parent_id', $comment->id)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getThread(Model $comment): Collection
    {
        $thread = collect([$comment]);
        $current = $comment;

        while ($current->parent_id) {
            $current = $this->find($current->parent_id);
            $thread->prepend($current);
        }

        return $thread;
    }
}

class CommentModerationRepository extends Repository
{
    public function getPendingComments(): Collection
    {
        return $this->query()
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getFlaggedComments(): Collection
    {
        return $this->query()
            ->where('flags_count', '>', 0)
            ->orderByDesc('flags_count')
            ->get();
    }

    public function getSpamComments(): Collection
    {
        return $this->query()
            ->where('status', 'spam')
            ->orderByDesc('created_at')
            ->get();
    }

    public function bulkUpdateStatus(array $commentIds, string $status): int
    {
        return $this->query()
            ->whereIn('id', $commentIds)
            ->update([
                'status' => $status,
                'moderated_at' => now(),
                'moderated_by' => auth()->id()
            ]);
    }
}

class CommentFlagRepository extends Repository
{
    public function flagComment(Model $comment, string $reason): Model
    {
        return DB::transaction(function() use ($comment, $reason) {
            $flag = $this->create([
                'comment_id' => $comment->id,
                'user_id' => auth()->id(),
                'reason' => $reason
            ]);

            $comment->increment('flags_count');

            return $flag;
        });
    }

    public function getFlags(Model $comment): Collection
    {
        return $this->query()
            ->where('comment_id', $comment->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
    }

    public function removeFlagsByUser(Model $comment, int $userId): int
    {
        return DB::transaction(function() use ($comment, $userId) {
            $count = $this->query()
                ->where('comment_id', $comment->id)
                ->where('user_id', $userId)
                ->delete();

            if ($count > 0) {
                $comment->decrement('flags_count', $count);
            }

            return $count;
        });
    }
}

class CommentSubscriptionRepository extends Repository
{
    public function subscribe(Model $content, int $userId): Model
    {
        return $this->create([
            'commentable_type' => get_class($content),
            'commentable_id' => $content->id,
            'user_id' => $userId
        ]);
    }

    public function unsubscribe(Model $content, int $userId): bool
    {
        return (bool)$this->query()
            ->where('commentable_type', get_class($content))
            ->where('commentable_id', $content->id)
            ->where('user_id', $userId)
            ->delete();
    }

    public function getSubscribers(Model $content): Collection
    {
        return $this->query()
            ->where('commentable_type', get_class($content))
            ->where('commentable_id', $content->id)
            ->with('user')
            ->get()
            ->pluck('user');
    }
}
