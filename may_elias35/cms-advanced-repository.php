```php
namespace App\Core\Repository\Advanced;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Core\Cache\CacheManager;
use App\Core\Contracts\RepositoryInterface;
use App\Core\Traits\QueryOptimizer;

abstract class AdvancedRepository implements RepositoryInterface
{
    use QueryOptimizer;

    protected Model $model;
    protected CacheManager $cache;
    protected array $defaultRelations = [];
    protected array $searchableFields = [];
    protected int $cacheDuration = 3600;

    public function __construct(Model $model, CacheManager $cache)
    {
        $this->model = $model;
        $this->cache = $cache;
    }

    public function findWithCache(int $id, array $relations = []): ?Model
    {
        $cacheKey = $this->getCacheKey("find.{$id}." . md5(serialize($relations)));

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($id, $relations) {
            return $this->model
                ->with($this->optimizeRelations(array_merge($this->defaultRelations, $relations)))
                ->find($id);
        });
    }

    public function findByAttributes(array $attributes, array $relations = []): ?Model
    {
        $cacheKey = $this->getCacheKey("attributes." . md5(serialize($attributes)));

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($attributes, $relations) {
            $query = $this->model->query();
            
            foreach ($attributes as $field => $value) {
                $query->where($field, $value);
            }

            return $query->with($this->optimizeRelations($relations))->first();
        });
    }

    public function findWhere(array $conditions, array $relations = [], array $orderBy = []): Collection
    {
        $cacheKey = $this->getCacheKey("where." . md5(serialize([$conditions, $relations, $orderBy])));

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($conditions, $relations, $orderBy) {
            $query = $this->model->query();
            
            foreach ($conditions as $field => $value) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }

            foreach ($orderBy as $field => $direction) {
                $query->orderBy($field, $direction);
            }

            return $query->with($this->optimizeRelations($relations))->get();
        });
    }

    public function search(string $term, array $fields = [], array $relations = []): Collection
    {
        $searchFields = $fields ?: $this->searchableFields;
        $cacheKey = $this->getCacheKey("search." . md5($term . serialize($searchFields)));

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($term, $searchFields, $relations) {
            $query = $this->model->query();

            $query->where(function (Builder $query) use ($term, $searchFields) {
                foreach ($searchFields as $field) {
                    $query->orWhere($field, 'LIKE', "%{$term}%");
                }
            });

            return $query->with($this->optimizeRelations($relations))->get();
        });
    }

    public function createWithRelations(array $data, array $relations = []): Model
    {
        $model = $this->model->create($data);

        if (isset($data['relations'])) {
            foreach ($data['relations'] as $relation => $ids) {
                $model->{$relation}()->sync($ids);
            }
        }

        $this->clearModelCache();

        return $model->load($this->optimizeRelations($relations));
    }

    public function updateWithRelations(int $id, array $data, array $relations = []): Model
    {
        $model = $this->findOrFail($id);
        $model->update($data);

        if (isset($data['relations'])) {
            foreach ($data['relations'] as $relation => $ids) {
                $model->{$relation}()->sync($ids);
            }
        }

        $this->clearModelCache();

        return $model->load($this->optimizeRelations($relations));
    }

    public function deleteWithRelations(int $id): bool
    {
        $model = $this->findOrFail($id);

        foreach ($model->getRelations() as $relation => $items) {
            $model->{$relation}()->detach();
        }

        $deleted = $model->delete();
        $this->clearModelCache();

        return $deleted;
    }

    protected function optimizeRelations(array $relations): array
    {
        return array_map(function ($relation) {
            if (is_string($relation) && method_exists($this->model, $relation)) {
                return $this->optimizeEagerLoading($relation);
            }
            return $relation;
        }, $relations);
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s.%s',
            strtolower(class_basename($this->model)),
            $key
        );
    }

    protected function clearModelCache(): void
    {
        $this->cache->tags([
            strtolower(class_basename($this->model))
        ])->flush();
    }
}

class AdvancedContentRepository extends AdvancedRepository
{
    protected array $defaultRelations = ['tags', 'media'];
    protected array $searchableFields = ['title', 'content', 'excerpt'];

    public function getPublishedWithFullRelations(): Collection
    {
        $cacheKey = $this->getCacheKey('published.full');

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () {
            return $this->model
                ->with($this->optimizeRelations([
                    'tags',
                    'media',
                    'author',
                    'categories',
                    'comments' => function ($query) {
                        $query->approved();
                    }
                ]))
                ->published()
                ->latest()
                ->get();
        });
    }

    public function findRelatedContent(int $contentId, int $limit = 5): Collection
    {
        $content = $this->findOrFail($contentId);
        $cacheKey = $this->getCacheKey("related.{$contentId}.{$limit}");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($content, $limit) {
            $tagIds = $content->tags->pluck('id');

            return $this->model
                ->with($this->optimizeRelations(['tags', 'media']))
                ->whereHas('tags', function ($query) use ($tagIds) {
                    $query->whereIn('tags.id', $tagIds);
                })
                ->where('id', '!=', $content->id)
                ->published()
                ->latest()
                ->limit($limit)
                ->get();
        });
    }
}

class AdvancedMediaRepository extends AdvancedRepository
{
    public function attachToContent(int $contentId, array $mediaIds, array $metadata = []): void
    {
        $content = app(AdvancedContentRepository::class)->findOrFail($contentId);

        $existingIds = $content->media()->pluck('id')->toArray();
        $newIds = array_diff($mediaIds, $existingIds);

        if (!empty($newIds)) {
            $attachData = array_fill_keys($newIds, $metadata);
            $content->media()->attach($attachData);
        }

        $this->clearModelCache();
        app(AdvancedContentRepository::class)->clearModelCache();
    }

    public function findByContentType(string $type, array $relations = []): Collection
    {
        $cacheKey = $this->getCacheKey("type.{$type}");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($type, $relations) {
            return $this->model
                ->with($this->optimizeRelations($relations))
                ->whereHas('contents', function ($query) use ($type) {
                    $query->where('type', $type);
                })
                ->get();
        });
    }
}
```

This implementation includes:

1. Advanced Repository Base Class:
   - Sophisticated caching mechanism
   - Relation optimization
   - Query building helpers
   - Search functionality

2. Advanced Content Repository:
   - Full relation loading
   - Related content finding
   - Performance optimizations

3. Advanced Media Repository:
   - Efficient content attachment
   - Type-based finding
   - Cache management

Would you like me to continue with:
1. Implementation of more advanced query optimization techniques
2. Caching strategy patterns
3. Batch operation handling
4. Transaction management
5. Event handling integration

Please let me know which aspect you'd like to focus on next.