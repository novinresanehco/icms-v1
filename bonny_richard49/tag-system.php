<?php

namespace App\Core\Tag\Contracts;

interface TagRepositoryInterface
{
    public function create(array $data): Tag;
    public function update(int $id, array $data): Tag;
    public function delete(int $id): bool;
    public function find(int $id): ?Tag;
    public function findBySlug(string $slug): ?Tag;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function attachToContent(int $contentId, array $tagIds): void;
    public function detachFromContent(int $contentId, array $tagIds): void;
    public function getContentTags(int $contentId): Collection;
}

namespace App\Core\Tag\Repositories;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Exceptions\TagNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class TagRepository implements TagRepositoryInterface
{
    protected Tag $model;

    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Tag
    {
        $tag = $this->model->create($data);
        $this->clearCache();
        return $tag;
    }

    public function update(int $id, array $data): Tag
    {
        $tag = $this->find($id);
        
        if (!$tag) {
            throw new TagNotFoundException("Tag with ID {$id} not found");
        }

        $tag->update($data);
        $this->clearCache();
        return $tag;
    }

    public function delete(int $id): bool
    {
        $tag = $this->find($id);
        
        if (!$tag) {
            throw new TagNotFoundException("Tag with ID {$id} not found");
        }

        $result = $tag->delete();
        $this->clearCache();
        return $result;
    }

    public function find(int $id): ?Tag
    {
        return Cache::tags(['tags'])
            ->remember("tag.{$id}", 3600, function () use ($id) {
                return $this->model->find($id);
            });
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Cache::tags(['tags'])
            ->remember("tag.slug.{$slug}", 3600, function () use ($slug) {
                return $this->model->where('slug', $slug)->first();
            });
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (isset($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
        }

        return $query->latest()->paginate($perPage);
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        $content = app(ContentRepositoryInterface::class)->find($contentId);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$contentId} not found");
        }

        $content->tags()->attach($tagIds);
        $this->clearContentTagCache($contentId);
    }

    public function detachFromContent(int $contentId, array $tagIds): void
    {
        $content = app(ContentRepositoryInterface::class)->find($contentId);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$contentId} not found");
        }

        $content->tags()->detach($tagIds);
        $this->clearContentTagCache($contentId);
    }

    public function getContentTags(int $contentId): Collection
    {
        return Cache::tags(['tags', 'content'])
            ->remember("content.{$contentId}.tags", 3600, function () use ($contentId) {
                return $this->model->whereHas('contents', function ($query) use ($contentId) {
                    $query->where('content_id', $contentId);
                })->get();
            });
    }

    protected function clearCache(): void
    {
        Cache::tags(['tags'])->flush();
    }

    protected function clearContentTagCache(int $contentId): void
    {
        Cache::tags(['tags', 'content'])->forget("content.{$contentId}.tags");
    }
}

namespace App\Core\Tag\Services;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Events\TagCreated;
use App\Core\Tag\Events\TagUpdated;
use App\Core\Tag\Events\TagDeleted;
use App\Core\Tag\Exceptions\TagValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TagService
{
    protected TagRepositoryInterface $repository;

    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function create(array $data): Tag
    {
        $this->validateTag($data);

        DB::beginTransaction();
        try {
            $tag = $this->repository->create($data);
            event(new TagCreated($tag));
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Tag
    {
        $this->validateTag($data, $id);

        DB::beginTransaction();
        try {
            $tag = $this->repository->update($id, $data);
            event(new TagUpdated($tag));
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $tag = $this->repository->find($id);
            $result = $this->repository->delete($id);
            event(new TagDeleted($tag));
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function syncContentTags(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        try {
            // First detach all existing tags
            $this->repository->detachFromContent($contentId, $this->repository->getContentTags($contentId)->pluck('id')->toArray());
            
            // Then attach new tags
            if (!empty($tagIds)) {
                $this->repository->attachToContent($contentId, $tagIds);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function validateTag(array $data, ?int $id = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ];

        if ($id === null) {
            $rules['slug'] = 'required|string|unique:tags,slug';
        } else {
            $rules['slug'] = "required|string|unique:tags,slug,{$id}";
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new TagValidationException($validator->errors()->first());
        }
    }
}

namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class)
            ->withTimestamps()
            ->withPivot('order');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (!$tag->slug) {
                $tag->slug = Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name') && !$tag->isDirty('slug')) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }
}