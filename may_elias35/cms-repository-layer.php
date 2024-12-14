<?php

namespace App\Core\Repository;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{RepositoryInterface, CacheableInterface};

abstract class BaseRepository implements RepositoryInterface, CacheableInterface
{
    protected Model $model;
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected MetricsCollector $metrics;
    protected string $cachePrefix;
    protected int $cacheTtl = 3600;

    public function __construct(
        Model $model,
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        MetricsCollector $metrics
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->cachePrefix = $this->getCachePrefix();
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            $this->cacheTtl,
            function() use ($id) {
                return $this->model->find($id);
            }
        );
    }

    public function findByCriteria(array $criteria): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('criteria', md5(serialize($criteria))),
            $this->cacheTtl,
            function() use ($criteria) {
                return $this->buildQuery($criteria)->get();
            }
        );
    }

    public function create(array $data): Model
    {
        return DB::transaction(function() use ($data) {
            $validated = $this->validateData($data);
            $model = $this->model->create($validated);
            $this->clearModelCache();
            $this->recordMetrics('create', $model->id);
            return $model;
        });
    }

    public function update(int $id, array $data): Model
    {
        return DB::transaction(function() use ($id, $data) {
            $model = $this->model->findOrFail($id);
            $validated = $this->validateData($data);
            $model->update($validated);
            $this->clearModelCache($id);
            $this->recordMetrics('update', $id);
            return $model->fresh();
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $model = $this->model->findOrFail($id);
            $result = $model->delete();
            $this->clearModelCache($id);
            $this->recordMetrics('delete', $id);
            return $result;
        });
    }

    protected function buildQuery(array $criteria): Builder
    {
        $query = $this->model->newQuery();

        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        if (isset($criteria['order_by'])) {
            $query->orderBy($criteria['order_by'], $criteria['order'] ?? 'asc');
        }

        if (isset($criteria['limit'])) {
            $query->limit($criteria['limit']);
        }

        return $query;
    }

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->getValidationRules());
    }

    protected function clearModelCache(int $id = null): void
    {
        if ($id) {
            $this->cache->forget($this->getCacheKey('find', $id));
        }
        $this->cache->tags($this->cachePrefix)->flush();
    }

    protected function recordMetrics(string $operation, int $id): void
    {
        $this->metrics->increment("repository.$operation", [
            'model' => $this->model::class,
            'id' => $id,
            'timestamp' => time()
        ]);
    }

    protected function getCacheKey(string $operation, mixed $identifier): string 
    {
        return sprintf(
            '%s:%s:%s',
            $this->cachePrefix,
            $operation,
            is_scalar($identifier) ? $identifier : md5(serialize($identifier))
        );
    }

    protected function getCachePrefix(): string
    {
        return strtolower(class_basename($this->model));
    }

    abstract protected function getValidationRules(): array;
}

class ContentRepository extends BaseRepository
{
    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'meta' => 'array',
            'publish_at' => 'date|nullable',
            'expire_at' => 'date|nullable|after:publish_at'
        ];
    }

    public function attachMedia(int $contentId, array $mediaIds): void
    {
        DB::transaction(function() use ($contentId, $mediaIds) {
            $content = $this->model->findOrFail($contentId);
            $content->media()->sync($mediaIds);
            $this->clearModelCache($contentId);
            $this->recordMetrics('attach_media', $contentId);
        });
    }

    public function setPermissions(int $contentId, array $permissions): void
    {
        DB::transaction(function() use ($contentId, $permissions) {
            $content = $this->model->findOrFail($contentId);
            $content->permissions()->sync($permissions);
            $this->clearModelCache($contentId);
            $this->recordMetrics('set_permissions', $contentId);
        });
    }

    public function version(int $contentId): ContentVersion
    {
        return DB::transaction(function() use ($contentId) {
            $content = $this->model->findOrFail($contentId);
            $version = $content->versions()->create([
                'content' => $content->getAttributes(),
                'created_by' => auth()->id()
            ]);
            $this->clearModelCache($contentId);
            $this->recordMetrics('version', $contentId);
            return $version;
        });
    }
}
