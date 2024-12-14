<?php

namespace App\Core\Tag\Contracts;

interface TagRepositoryInterface
{
    public function create(array $data): Tag;
    public function update(int $id, array $data): Tag;
    public function delete(int $id): bool;
    public function find(int $id): ?Tag;
    public function findBySlug(string $slug): ?Tag;
    public function getTagsForContent(int $contentId): Collection;
    public function attachToContent(int $contentId, array $tagIds): void;
    public function detachFromContent(int $contentId, array $tagIds): void;
}

namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name') && !$tag->isDirty('slug')) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_tags')
            ->withTimestamps()
            ->withPivot(['order', 'created_by']);
    }
}

namespace App\Core\Tag\Repositories;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TagRepository implements TagRepositoryInterface
{
    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    public function update(int $id, array $data): Tag
    {
        $tag = $this->find($id);
        
        if (!$tag) {
            throw new ModelNotFoundException("Tag not found with ID: {$id}");
        }

        $tag->update($data);
        return $tag->fresh();
    }

    public function delete(int $id): bool
    {
        $tag = $this->find($id);
        
        if (!$tag) {
            throw new ModelNotFoundException("Tag not found with ID: {$id}");
        }

        return $tag->delete();
    }

    public function find(int $id): ?Tag
    {
        return Tag::find($id);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Tag::where('slug', $slug)->first();
    }

    public function getTagsForContent(int $contentId): Collection
    {
        return Tag::whereHas('contents', function ($query) use ($contentId) {
            $query->where('content_id', $contentId);
        })->get();
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        $content = Content::findOrFail($contentId);
        $content->tags()->attach($tagIds, [
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
    }

    public function detachFromContent(int $contentId, array $tagIds): void
    {
        $content = Content::findOrFail($contentId);
        $content->tags()->detach($tagIds);
    }
}

namespace App\Core\Tag\Services;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Core\Tag\Events\TagAttachedToContent;
use App\Core\Tag\Events\TagDetachedFromContent;
use App\Core\Tag\Exceptions\TagOperationException;

class TagService
{
    private TagRepositoryInterface $repository;
    private int $cacheMinutes;

    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
        $this->cacheMinutes = config('cache.tag_ttl', 60);
    }

    public function createTag(array $data): Tag
    {
        DB::beginTransaction();
        try {
            $tag = $this->repository->create($data);
            
            Cache::tags(['tags'])->flush();
            
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagOperationException("Failed to create tag: {$e->getMessage()}");
        }
    }

    public function attachTagsToContent(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        try {
            $this->repository->attachToContent($contentId, $tagIds);
            
            foreach ($tagIds as $tagId) {
                event(new TagAttachedToContent($contentId, $tagId));
            }
            
            Cache::tags(['content:'.$contentId, 'tags'])->flush();
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagOperationException("Failed to attach tags: {$e->getMessage()}");
        }
    }

    public function getTagsForContent(int $contentId): Collection
    {
        return Cache::tags(['content:'.$contentId, 'tags'])
            ->remember("content.{$contentId}.tags", $this->cacheMinutes, function () use ($contentId) {
                return $this->repository->getTagsForContent($contentId);
            });
    }
}
