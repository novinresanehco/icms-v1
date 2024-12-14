<?php

namespace App\Core\Repositories;

use App\Core\Contracts\{
    RepositoryInterface,
    CacheableInterface,
    SearchableInterface,
    AuditableInterface
};
use App\Core\Events\{
    EntityCreated,
    EntityUpdated,
    EntityDeleted,
    EntityRestored
};
use App\Core\Exceptions\{
    RepositoryException,
    ValidationException,
    EntityNotFoundException
};
use Illuminate\Database\Eloquent\{Model, Builder, SoftDeletes};
use Illuminate\Support\{Collection, Facades\DB, Facades\Cache, Facades\Validator};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class AbstractRepository implements RepositoryInterface, CacheableInterface, SearchableInterface
{
    protected Model $model;
    protected array $searchable = [];
    protected array $with = [];
    protected array $withCount = [];
    protected array $validationRules = [];
    protected bool $useCache = true;
    protected int $cacheTTL = 3600;
    protected array $criteria = [];

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        $key = $this->getCacheKey(__METHOD__, $id, $columns);
        return $this->useCache ? Cache::tags($this->getCacheTags())
            ->remember($key, $this->cacheTTL, fn() => 
                $this->query()->with($this->with)->find($id, $columns)
            ) : $this->query()->with($this->with)->find($id, $columns);
    }

    public function findOrFail(int $id, array $columns = ['*']): Model
    {
        $result = $this->find($id, $columns);
        if (!$result) {
            throw new EntityNotFoundException(class_basename($this->model));
        }
        return $result;
    }

    public function all(array $columns = ['*']): Collection
    {
        $key = $this->getCacheKey(__METHOD__, $columns);
        return $this->useCache ? Cache::tags($this->getCacheTags())
            ->remember($key, $this->cacheTTL, fn() => 
                $this->query()->with($this->with)->get($columns)
            ) : $this->query()->with($this->with)->get($columns);
    }

    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        return $this->query()->with($this->with)->paginate($perPage, $columns);
    }

    public function create(array $data): Model
    {
        $this->validate($data);
        
        return DB::transaction(function() use ($data) {
            $entity = $this->model->create($data);
            $this->clearCache();
            event(new EntityCreated($entity));
            return $entity;
        });
    }

    public function update(Model $model, array $data): bool
    {
        $this->validate($data, $model->id);
        
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

    public function restore(Model $model): bool
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($model))) {
            throw new RepositoryException("Model does not use soft deletes");
        }

        return DB::transaction(function() use ($model) {
            $restored = $model->restore();
            if ($restored) {
                $this->clearCache();
                event(new EntityRestored($model));
            }
            return $restored;
        });
    }

    public function search(string $query): Collection
    {
        $searchQuery = $this->query();
        
        foreach ($this->searchable as $field) {
            $searchQuery->orWhere($field, 'LIKE', "%{$query}%");
        }

        return $searchQuery->with($this->with)->get();
    }

    protected function validate(array $data, ?int $id = null): void
    {
        if (empty($this->validationRules)) {
            return;
        }

        $rules = $this->validationRules;
        if ($id) {
            $rules = $this->mapUniqueRules($rules, $id);
        }

        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function mapUniqueRules(array $rules, int $id): array
    {
        return collect($rules)->map(function($rule) use ($id) {
            if (is_string($rule) && str_contains($rule, 'unique:')) {
                return $rule . ',' . $id;
            }
            return $rule;
        })->toArray();
    }

    protected function query(): Builder
    {
        $query = $this->model->newQuery();
        
        foreach ($this->criteria as $criteria) {
            $query = $criteria->apply($query);
        }

        if (!empty($this->withCount)) {
            $query->withCount($this->withCount);
        }

        return $query;
    }

    protected function getCacheKey(string $method, ...$args): string
    {
        return sprintf(
            '%s.%s.%s',
            class_basename($this->model),
            $method,
            md5(serialize($args))
        );
    }

    protected function getCacheTags(): array
    {
        return [
            'repository',
            class_basename($this->model)
        ];
    }

    public function clearCache(): void
    {
        if ($this->useCache) {
            Cache::tags($this->getCacheTags())->flush();
        }
    }
}

class ContentRepository extends AbstractRepository
{
    protected array $searchable = ['title', 'content', 'meta_description'];
    protected array $with = ['category', 'tags', 'author'];
    protected array $withCount = ['comments', 'likes'];
    
    protected array $validationRules = [
        'title' => 'required|min:3|max:255',
        'slug' => 'required|unique:contents',
        'content' => 'required',
        'status' => 'required|in:draft,published,archived',
        'category_id' => 'required|exists:categories,id',
        'author_id' => 'required|exists:users,id',
        'published_at' => 'nullable|date',
        'meta_title' => 'nullable|max:60',
        'meta_description' => 'nullable|max:160',
        'featured_image' => 'nullable|string'
    ];

    public function findBySlug(string $slug): ?Model
    {
        $key = $this->getCacheKey(__METHOD__, $slug);
        return $this->useCache ? Cache::tags($this->getCacheTags())
            ->remember($key, $this->cacheTTL, fn() => 
                $this->query()->where('slug', $slug)->first()
            ) : $this->query()->where('slug', $slug)->first();
    }

    public function findPublished(): Collection
    {
        $key = $this->getCacheKey(__METHOD__);
        return $this->useCache ? Cache::tags($this->getCacheTags())
            ->remember($key, $this->cacheTTL, fn() => 
                $this->query()
                    ->where('status', 'published')
                    ->where('published_at', '<=', now())
                    ->orderBy('published_at', 'desc')
                    ->get()
            ) : $this->query()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderBy('published_at', 'desc')
                ->get();
    }

    public function updateStatus(Model $model, string $status): bool
    {
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            throw new ValidationException('Invalid status');
        }

        $data = ['status' => $status];
        if ($status === 'published' && !$model->published_at) {
            $data['published_at'] = now();
        }

        return $this->update($model, $data);
    }
}
