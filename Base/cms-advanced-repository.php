<?php

namespace App\Core\Repositories;

use App\Core\Models\{Content, Category, Tag, Media};
use App\Core\Contracts\{SearchableInterface, VersionableInterface};
use App\Core\Events\{VersionCreated, ContentRestored};
use App\Core\Exceptions\{RepositoryException, VersionException};
use Illuminate\Database\Eloquent\{Model, Builder, Collection, SoftDeletes};
use Illuminate\Support\{Str, Facades\DB, Facades\Cache};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait HasVersions
{
    protected function createVersion(Model $model, string $reason = ''): void
    {
        DB::transaction(function() use ($model, $reason) {
            $version = $model->versions()->create([
                'content' => json_encode($model->getAttributes()),
                'user_id' => auth()->id(),
                'reason' => $reason,
                'hash' => $this->generateVersionHash($model),
                'version' => $model->versions()->count() + 1
            ]);

            event(new VersionCreated($model, $version));
        });
    }

    protected function generateVersionHash(Model $model): string
    {
        $data = $model->getAttributes();
        ksort($data);
        return hash('sha256', json_encode($data));
    }
}

abstract class BaseRepository
{
    use HasVersions;

    protected Model $model;
    protected array $with = [];
    protected array $searchFields = [];
    protected bool $useCache = true;
    protected int $cacheTTL = 3600;
    protected array $criteria = [];

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        $key = $this->getCacheKey('find', $id, $columns);
        $query = $this->model->with($this->with);
        
        return $this->useCache ? 
            Cache::tags($this->getCacheTags())->remember(
                $key, 
                $this->cacheTTL, 
                fn() => $query->find($id, $columns)
            ) : 
            $query->find($id, $columns);
    }

    public function findOrFail(int $id, array $columns = ['*']): Model
    {
        $result = $this->find($id, $columns);
        
        if (!$result) {
            throw new RepositoryException("Entity not found");
        }
        
        return $result;
    }

    public function create(array $attributes): Model
    {
        return DB::transaction(function() use ($attributes) {
            $model = $this->model->create($attributes);
            
            if ($model instanceof VersionableInterface) {
                $this->createVersion($model, 'Initial version');
            }
            
            $this->clearCache();
            return $model;
        });
    }

    public function update(Model $model, array $attributes): bool
    {
        return DB::transaction(function() use ($model, $attributes) {
            if ($model instanceof VersionableInterface) {
                $this->createVersion($model, 'Pre-update version');
            }
            
            $updated = $model->update($attributes);
            
            if ($updated) {
                $this->clearCache();
            }
            
            return $updated;
        });
    }

    public function delete(Model $model): bool
    {
        $deleted = $model->delete();
        
        if ($deleted) {
            $this->clearCache();
        }
        
        return $deleted;
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
        return ['repository', class_basename($this->model)];
    }

    protected function clearCache(): void
    {
        if ($this->useCache) {
            Cache::tags($this->getCacheTags())->flush();
        }
    }
}

class ContentRepository extends BaseRepository implements SearchableInterface
{
    protected array $searchFields = ['title', 'content', 'meta_description'];
    protected array $with = ['category', 'tags', 'media', 'author'];

    public function findBySlug(string $slug): ?Model
    {
        $key = $this->getCacheKey('findBySlug', $slug);
        
        return $this->useCache ? 
            Cache::tags($this->getCacheTags())->remember(
                $key,
                $this->cacheTTL,
                fn() => $this->model->with($this->with)->where('slug', $slug)->first()
            ) :
            $this->model->with($this->with)->where('slug', $slug)->first();
    }

    public function findPublished(array $relations = []): Collection
    {
        $with = array_merge($this->with, $relations);
        $key = $this->getCacheKey('findPublished', $with);
        
        $query = $this->model->with($with)
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc');

        return $this->useCache ?
            Cache::tags($this->getCacheTags())->remember(
                $key,
                $this->cacheTTL,
                fn() => $query->get()
            ) :
            $query->get();
    }

    public function search(string $term): Collection
    {
        $query = $this->model->where(function($query) use ($term) {
            foreach ($this->searchFields as $field) {
                $query->orWhere($field, 'LIKE', "%{$term}%");
            }
        })->where('status', 'published');

        return $query->with($this->with)->get();
    }

    public function updateStatus(Model $content, string $status): bool
    {
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            throw new RepositoryException('Invalid status');
        }

        $attributes = ['status' => $status];
        
        if ($status === 'published' && !$content->published_at) {
            $attributes['published_at'] = now();
        }

        return $this->update($content, $attributes);
    }

    public function restoreVersion(Model $content, int $versionId): bool
    {
        return DB::transaction(function() use ($content, $versionId) {
            $version = $content->versions()->findOrFail($versionId);
            $data = json_decode($version->content, true);
            
            $updated = $content->update($data);
            
            if ($updated) {
                $this->createVersion($content, "Restored to version {$version->version}");
                event(new ContentRestored($content, $version));
                $this->clearCache();
            }
            
            return $updated;
        });
    }

    public function getVersions(Model $content): Collection
    {
        return $content->versions()
            ->with('user')
            ->orderBy('version', 'desc')
            ->get();
    }
}

class CategoryRepository extends BaseRepository
{
    protected array $with = ['parent', 'children'];

    public function findActive(): Collection
    {
        $key = $this->getCacheKey('findActive');
        
        return $this->useCache ?
            Cache::tags($this->getCacheTags())->remember(
                $key,
                $this->cacheTTL,
                fn() => $this->model->with($this->with)
                    ->where('active', true)
                    ->orderBy('sort_order')
                    ->get()
            ) :
            $this->model->with($this->with)
                ->where('active', true)
                ->orderBy('sort_order')
                ->get();
    }

    public function reorder(array $items): bool
    {
        return DB::transaction(function() use ($items) {
            foreach ($items as $index => $item) {
                $this->model->where('id', $item['id'])
                    ->update(['sort_order' => $index + 1]);
            }
            
            $this->clearCache();
            return true;
        });
    }
}

class TagRepository extends BaseRepository
{
    public function findByName(string $name): ?Model
    {
        $key = $this->getCacheKey('findByName', $name);
        
        return $this->useCache ?
            Cache::tags($this->getCacheTags())->remember(
                $key,
                $this->cacheTTL,
                fn() => $this->model->where('name', $name)->first()
            ) :
            $this->model->where('name', $name)->first();
    }

    public function findOrCreateByName(string $name): Model
    {
        return DB::transaction(function() use ($name) {
            $tag = $this->findByName($name);
            
            if (!$tag) {
                $tag = $this->create([
                    'name' => $name,
                    'slug' => Str::slug($name)
                ]);
            }
            
            return $tag;
        });
    }

    public function getPopular(int $limit = 10): Collection
    {
        $key = $this->getCacheKey('getPopular', $limit);
        
        return $this->useCache ?
            Cache::tags($this->getCacheTags())->remember(
                $key,
                $this->cacheTTL,
                fn() => $this->model->withCount('contents')
                    ->orderByDesc('contents_count')
                    ->limit($limit)
                    ->get()
            ) :
            $this->model->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get();
    }
}
