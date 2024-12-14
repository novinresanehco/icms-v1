<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    CacheManager,
    AuditLogger,
    EncryptionService
};

class ContentRepository implements RepositoryInterface
{
    private Model $model;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;
    private EncryptionService $encryption;

    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $logger,
        EncryptionService $encryption
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->encryption = $encryption;
    }

    public function create(array $data): Model
    {
        $this->validator->validateData($data);
        $this->security->validateOperation('create', $data);

        $encrypted = $this->encryption->encryptSensitiveData($data);
        $model = $this->model->create($encrypted);
        
        $this->cache->invalidateGroup('content');
        $this->logger->logCreation($model);

        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $this->validator->validateData($data);
        $this->security->validateOperation('update', $data);

        $model = $this->findOrFail($id);
        $encrypted = $this->encryption->encryptSensitiveData($data);
        
        $model->update($encrypted);
        
        $this->cache->invalidateGroup('content');
        $this->logger->logUpdate($model);

        return $model;
    }

    public function delete(int $id): bool
    {
        $this->security->validateOperation('delete', ['id' => $id]);

        $model = $this->findOrFail($id);
        $result = $model->delete();

        $this->cache->invalidateGroup('content');
        $this->logger->logDeletion($model);

        return $result;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->model->find($id);
        });
    }

    public function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new ModelNotFoundException("Content with ID $id not found");
        }

        return $model;
    }

    public function list(array $filters = []): Collection
    {
        $this->validator->validateFilters($filters);
        $cacheKey = $this->generateCacheKey($filters);

        return $this->cache->remember($cacheKey, function() use ($filters) {
            $query = $this->model->newQuery();

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['category'])) {
                $query->whereHas('categories', function($q) use ($filters) {
                    $q->where('categories.id', $filters['category']);
                });
            }

            if (isset($filters['tag'])) {
                $query->whereHas('tags', function($q) use ($filters) {
                    $q->where('tags.id', $filters['tag']);
                });
            }

            if (isset($filters['author'])) {
                $query->where('author_id', $filters['author']);
            }

            if (isset($filters['search'])) {
                $query->where(function($q) use ($filters) {
                    $q->where('title', 'like', "%{$filters['search']}%")
                      ->orWhere('content', 'like', "%{$filters['search']}%");
                });
            }

            $perPage = $filters['per_page'] ?? 15;
            return $query->paginate($perPage);
        });
    }

    public function attachMedia(int $contentId, array $media): void
    {
        $this->validator->validateMedia($media);
        $content = $this->findOrFail($contentId);
        
        $content->media()->attach($media['id'], [
            'type' => $media['type'],
            'url' => $media['url'],
            'title' => $media['title']
        ]);

        $this->cache->invalidateGroup('content');
        $this->logger->logMediaAttachment($content, $media);
    }

    public function syncCategories(int $contentId, array $categories): void
    {
        $content = $this->findOrFail($contentId);
        $content->categories()->sync($categories);
        
        $this->cache->invalidateGroup('content');
        $this->logger->logCategorySync($content, $categories);
    }

    public function syncTags(int $contentId, array $tags): void
    {
        $content = $this->findOrFail($contentId);
        $content->tags()->sync($tags);
        
        $this->cache->invalidateGroup('content');
        $this->logger->logTagSync($content, $tags);
    }

    public function detachAllMedia(int $contentId): void
    {
        $content = $this->findOrFail($contentId);
        $content->media()->detach();
        
        $this->cache->invalidateGroup('content');
        $this->logger->logMediaDetachment($content);
    }

    public function detachAllCategories(int $contentId): void
    {
        $content = $this->findOrFail($contentId);
        $content->categories()->detach();
        
        $this->cache->invalidateGroup('content');
        $this->logger->logCategoryDetachment($content);
    }

    public function detachAllTags(int $contentId): void
    {
        $content = $this->findOrFail($contentId);
        $content->tags()->detach();
        
        $this->cache->invalidateGroup('content');
        $this->logger->logTagDetachment($content);
    }

    private function generateCacheKey(array $filters): string
    {
        return 'content.list.' . md5(serialize($filters));
    }
}
