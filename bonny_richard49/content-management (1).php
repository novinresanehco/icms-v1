<?php

namespace App\Core\Content\Contracts;

interface ContentRepositoryInterface
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function find(int $id): ?Content;
    public function findBySlug(string $slug): ?Content;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
}

namespace App\Core\Content\Repositories;

use App\Core\Content\Models\Content;
use App\Core\Content\Contracts\ContentRepositoryInterface;
use App\Core\Content\Exceptions\ContentNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class ContentRepository implements ContentRepositoryInterface
{
    protected Content $model;

    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Content
    {
        $content = $this->model->create($data);
        $this->clearCache();
        return $content;
    }

    public function update(int $id, array $data): Content
    {
        $content = $this->find($id);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }

        $content->update($data);
        $this->clearCache();
        return $content;
    }

    public function delete(int $id): bool
    {
        $content = $this->find($id);
        
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }

        $result = $content->delete();
        $this->clearCache();
        return $result;
    }

    public function find(int $id): ?Content
    {
        return Cache::tags(['content'])
            ->remember("content.{$id}", 3600, function () use ($id) {
                return $this->model->find($id);
            });
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::tags(['content'])
            ->remember("content.slug.{$slug}", 3600, function () use ($slug) {
                return $this->model->where('slug', $slug)->first();
            });
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'like', "%{$filters['search']}%")
                  ->orWhere('content', 'like', "%{$filters['search']}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    protected function clearCache(): void
    {
        Cache::tags(['content'])->flush();
    }
}

namespace App\Core\Content\Services;

use App\Core\Content\Contracts\ContentRepositoryInterface;
use App\Core\Content\Events\ContentCreated;
use App\Core\Content\Events\ContentUpdated;
use App\Core\Content\Events\ContentDeleted;
use App\Core\Content\Exceptions\ContentValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ContentService
{
    protected ContentRepositoryInterface $repository;

    public function __construct(ContentRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function create(array $data): Content
    {
        $this->validateContent($data);

        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            event(new ContentCreated($content));
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        $this->validateContent($data, $id);

        DB::beginTransaction();
        try {
            $content = $this->repository->update($id, $data);
            event(new ContentUpdated($content));
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = $this->repository->find($id);
            $result = $this->repository->delete($id);
            event(new ContentDeleted($content));
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function validateContent(array $data, ?int $id = null): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'category_id' => 'required|exists:categories,id',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:255'
        ];

        if ($id === null) {
            $rules['slug'] = 'required|string|unique:contents,slug';
        } else {
            $rules['slug'] = "required|string|unique:contents,slug,{$id}";
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ContentValidationException($validator->errors()->first());
        }
    }
}

namespace App\Core\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'category_id',
        'meta_title',
        'meta_description',
        'published_at'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withTimestamps()
            ->withPivot('order');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            if (!$content->published_at && $content->status === 'published') {
                $content->published_at = now();
            }
        });

        static::updating(function ($content) {
            if (!$content->published_at && $content->status === 'published') {
                $content->published_at = now();
            }
        });
    }
}
