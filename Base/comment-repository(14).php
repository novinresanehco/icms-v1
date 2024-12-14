<?php

namespace App\Core\Repositories;

use App\Models\Comment;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class CommentRepository extends AdvancedRepository
{
    protected $model = Comment::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForContent($content, array $options = []): Collection
    {
        return $this->executeQuery(function() use ($content, $options) {
            $query = $this->model
                ->where('commentable_type', get_class($content))
                ->where('commentable_id', $content->id)
                ->with(['author', 'replies'])
                ->orderBy('created_at', $options['order'] ?? 'desc');
                
            if (!($options['includeUnapproved'] ?? false)) {
                $query->where('status', 'approved');
            }
            
            return $query->get();
        });
    }

    public function approve(Comment $comment): void
    {
        $this->executeTransaction(function() use ($comment) {
            $comment->update(['status' => 'approved']);
            $this->cache->forget("content.{$comment->commentable_id}.comments");
        });
    }

    public function markAsSpam(Comment $comment): void
    {
        $this->executeTransaction(function() use ($comment) {
            $comment->update(['status' => 'spam']);
            $this->cache->forget("content.{$comment->commentable_id}.comments");
        });
    }

    public function addReply(Comment $parent, array $data): Comment
    {
        return $this->executeTransaction(function() use ($parent, $data) {
            $reply = $this->create(array_merge($data, [
                'parent_id' => $parent->id,
                'commentable_type' => $parent->commentable_type,
                'commentable_id' => $parent->commentable_id
            ]));
            
            $this->cache->forget("content.{$parent->commentable_id}.comments");
            return $reply;
        });
    }
}
