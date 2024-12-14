<?php

namespace App\Core\Repositories;

use App\Core\Models\{Content, Category, Tag, Media, User};
use App\Core\Contracts\{RepositoryInterface, CacheableInterface};
use App\Core\Events\{EntityCreated, EntityUpdated, EntityDeleted};
use App\Core\Exceptions\{RepositoryException, ValidationException};
use Illuminate\Database\Eloquent\{Model, Builder, Collection};
use Illuminate\Support\{Facades\DB, Facades\Cache, Facades\Validator};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class Repository implements RepositoryInterface, CacheableInterface
{
    protected Model $model;
    protected array $rules = [];
    protected array $relationships = [];
    protected array $searchable = [];
    protected int $cacheTTL = 3600;
    protected array $criteria = [];

    public function find(int $id, array $with = []): ?Model
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()->with($with)->find($id)
        );
    }

    public function findWhere(array $conditions, array $with = []): ?Model
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()->where($conditions)->with($with)->first()
        );
    }

    public function all(array $with = []): Collection
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()->with($with)->get()
        );
    }

    public function paginate(int $perPage = 15, array $with = []): LengthAwarePaginator
    {
        return $this->query()->with($with)->paginate($perPage);
    }

    public function create(array $data): Model
    {
        $this->validate($data);

        return DB::transaction(function() use ($data) {
            $model = $this->query()->create($data);
            $this->clearCache();
            event(new EntityCreated($model));
            return $model;
        });
    }

    public function update(Model $model, array $data): bool
    {
        $this->validate($data, $model);

        return DB::transaction(function() use ($model, $data) {
            $updated = $model->update($data);
            if ($updated) {
                $this->clearCache();
                event(new EntityUpdated($model));
            }
            return $updated;
        });
    }

    public function delete(Model $model): bool
    {
        return DB::transaction(function() use ($model) {
            $deleted = $model->delete();
            if ($deleted) {
                $this->clearCache();
                event(new EntityDeleted($model));
            }
            return $deleted;
        });
    }

    protected function query(): Builder
    {
        $query = $this->model->newQuery();
        foreach ($this->criteria as $criteria) {
            $criteria->apply($query);
        }
        return $query;
    }

    protected function validate(array $data, ?Model $model = null): void
    {
        if (empty($this->rules)) return;

        $rules = $this->rules;
        if ($model) {
            $rules = $this->excludeUniqueRules($rules, $model);
        }

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }

    protected function cache(string $key, array $args, callable $callback)
    {
        $cacheKey = $this->getCacheKey($key, $args);
        return Cache::tags($this->getCacheTags())
            ->remember($cacheKey, $this->cacheTTL, $callback);
    }

    protected function getCacheKey(string $key, array $args): string
    {
        return sprintf(
            '%s.%s.%s',
            class_basename($this->model),
            $key,
            md5(serialize($args))
        );
    }

    protected function getCacheTags(): array
    {
        return ['repository', class_basename($this->model)];
    }

    public function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}

class ContentRepository extends Repository
{
    protected array $rules = [
        'title' => 'required|min:3|max:255',
        'slug' => 'required|unique:contents',
        'content' => 'required',
        'status' => 'required|in:draft,published,archived',
        'category_id' => 'required|exists:categories,id',
        'meta_description' => 'nullable|max:160'
    ];

    protected array $relationships = ['category', 'tags', 'author', 'media'];
    protected array $searchable = ['title', 'content', 'meta_description'];

    public function findBySlug(string $slug): ?Model
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()
                ->where('slug', $slug)
                ->with($this->relationships)
                ->first()
        );
    }

    public function findPublished(): Collection
    {
        return $this->cache(__METHOD__, [], fn() => 
            $this->query()
                ->with($this->relationships)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderByDesc('published_at')
                ->get()
        );
    }

    public function search(string $term): Collection
    {
        $query = $this->query()->where(function($query) use ($term) {
            foreach ($this->searchable as $field) {
                $query->orWhere($field, 'LIKE', "%{$term}%");
            }
        });

        return $query->with($this->relationships)->get();
    }

    public function updateStatus(Model $content, string $status): bool
    {
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            throw new RepositoryException('Invalid status');
        }

        $data = ['status' => $status];
        if ($status === 'published' && !$content->published_at) {
            $data['published_at'] = now();
        }

        return $this->update($content, $data);
    }

    public function attachTags(Model $content, array $tagIds): void
    {
        DB::transaction(function() use ($content, $tagIds) {
            $content->tags()->sync($tagIds);
            $this->clearCache();
        });
    }

    public function attachMedia(Model $content, array $mediaIds): void
    {
        DB::transaction(function() use ($content, $mediaIds) {
            $content->media()->sync($mediaIds);
            $this->clearCache();
        });
    }
}

class CategoryRepository extends Repository
{
    protected array $rules = [
        'name' => 'required|max:255',
        'slug' => 'required|unique:categories',
        'description' => 'nullable',
        'parent_id' => 'nullable|exists:categories,id'
    ];

    public function findBySlug(string $slug): ?Model
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()
                ->where('slug', $slug)
                ->with(['parent', 'children'])
                ->first()
        );
    }

    public function reorder(array $items): bool
    {
        return DB::transaction(function() use ($items) {
            foreach ($items as $index => $item) {
                $this->query()
                    ->where('id', $item['id'])
                    ->update(['sort_order' => $index + 1]);
            }
            $this->clearCache();
            return true;
        });
    }

    public function getTree(): Collection
    {
        return $this->cache(__METHOD__, [], fn() => 
            $this->query()
                ->whereNull('parent_id')
                ->with(['children' => fn($q) => $q->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get()
        );
    }
}

class TagRepository extends Repository
{
    protected array $rules = [
        'name' => 'required|max:255',
        'slug' => 'required|unique:tags'
    ];

    public function findByName(string $name): ?Model
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()->where('name', $name)->first()
        );
    }

    public function findPopular(int $limit = 10): Collection
    {
        return $this->cache(__METHOD__, func_get_args(), fn() => 
            $this->query()
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }
}
