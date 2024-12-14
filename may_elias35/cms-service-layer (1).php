<?php

namespace App\Core\Services;

use App\Core\Repository\BaseRepository;
use App\Core\Exceptions\ServiceException;
use App\Core\Interfaces\ValidatorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Exception;

abstract class BaseService
{
    protected BaseRepository $repository;
    protected ValidatorInterface $validator;
    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'cms:service',
        'tags' => []
    ];

    public function __construct(BaseRepository $repository, ValidatorInterface $validator)
    {
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function find(int $id)
    {
        try {
            return $this->repository->find($id);
        } catch (Exception $e) {
            throw new ServiceException("Error finding resource: {$e->getMessage()}");
        }
    }

    public function findOrFail(int $id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (Exception $e) {
            throw new ServiceException("Error finding resource: {$e->getMessage()}");
        }
    }

    public function all()
    {
        try {
            return $this->repository->all();
        } catch (Exception $e) {
            throw new ServiceException("Error retrieving resources: {$e->getMessage()}");
        }
    }

    public function create(array $data)
    {
        $this->validateData($data);

        DB::beginTransaction();
        try {
            $model = $this->repository->create($data);
            $this->afterCreate($model);
            DB::commit();
            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new ServiceException("Error creating resource: {$e->getMessage()}");
        }
    }

    public function update(int $id, array $data)
    {
        $this->validateData($data, $id);

        DB::beginTransaction();
        try {
            $model = $this->repository->update($id, $data);
            $this->afterUpdate($model);
            DB::commit();
            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new ServiceException("Error updating resource: {$e->getMessage()}");
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $result = $this->repository->delete($id);
            $this->afterDelete($id);
            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new ServiceException("Error deleting resource: {$e->getMessage()}");
        }
    }

    protected function validateData(array $data, ?int $id = null): void
    {
        $this->validator->validate($data, $id);
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cacheConfig['prefix'],
            $this->getResourceName(),
            $key
        );
    }

    protected function remember(string $key, callable $callback)
    {
        if (empty($this->cacheConfig['tags'])) {
            return Cache::remember(
                $this->getCacheKey($key),
                $this->cacheConfig['ttl'],
                $callback
            );
        }

        return Cache::tags($this->cacheConfig['tags'])->remember(
            $this->getCacheKey($key),
            $this->cacheConfig['ttl'],
            $callback
        );
    }

    protected function clearCache(): void
    {
        if (empty($this->cacheConfig['tags'])) {
            Cache::delete($this->getCacheKey('*'));
            return;
        }

        Cache::tags($this->cacheConfig['tags'])->flush();
    }

    protected function getResourceName(): string
    {
        return strtolower(str_replace('Service', '', class_basename($this)));
    }

    protected function afterCreate($model): void
    {
        // Hook for post-create operations
    }

    protected function afterUpdate($model): void
    {
        // Hook for post-update operations
    }

    protected function afterDelete(int $id): void
    {
        // Hook for post-delete operations
    }
}

class ContentService extends BaseService
{
    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'cms:service',
        'tags' => ['content']
    ];

    public function findBySlug(string $slug)
    {
        try {
            return $this->remember("slug.{$slug}", function() use ($slug) {
                return $this->repository->findBySlug($slug);
            });
        } catch (Exception $e) {
            throw new ServiceException("Error finding content by slug: {$e->getMessage()}");
        }
    }

    public function findPublished()
    {
        try {
            return $this->remember('published', function() {
                return $this->repository->findPublished();
            });
        } catch (Exception $e) {
            throw new ServiceException("Error finding published content: {$e->getMessage()}");
        }
    }

    public function updateStatus(int $id, string $status)
    {
        DB::beginTransaction();
        try {
            $model = $this->repository->updateStatus($id, $status);
            $this->afterStatusUpdate($model);
            DB::commit();
            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new ServiceException("Error updating content status: {$e->getMessage()}");
        }
    }

    protected function afterStatusUpdate($model): void
    {
        $this->clearCache();
        event(new ContentStatusUpdated($model));
    }
}

class TagService extends BaseService 
{
    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'cms:service',
        'tags' => ['tag']
    ];

    public function findByName(string $name)
    {
        try {
            return $this->remember("name.{$name}", function() use ($name) {
                return $this->repository->findByName($name);
            });
        } catch (Exception $e) {
            throw new ServiceException("Error finding tag by name: {$e->getMessage()}");
        }
    }

    public function getPopularTags(int $limit = 10)
    {
        try {
            return $this->remember("popular.{$limit}", function() use ($limit) {
                return $this->repository->getPopularTags($limit);
            });
        } catch (Exception $e) {
            throw new ServiceException("Error getting popular tags: {$e->getMessage()}");
        }
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        try {
            $this->repository->attachToContent($contentId, $tagIds);
            $this->afterAttachToContent($contentId, $tagIds);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new ServiceException("Error attaching tags to content: {$e->getMessage()}");
        }
    }

    protected function afterAttachToContent(int $contentId, array $tagIds): void
    {
        $this->clearCache();
        Cache::tags(['content'])->flush();
        event(new TagsAttachedToContent($contentId, $tagIds));
    }
}

class MediaService extends BaseService
{
    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'cms:service',
        'tags' => ['media']
    ];

    public function findByType(string $type)
    {
        try {
            return $this->remember("type.{$type}", function() use ($type) {
                return $this->repository->findByType($type);
            });
        } catch (Exception $e) {
            throw new ServiceException("Error finding media by type: {$e->getMessage()}");
        }
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        DB::beginTransaction();
        try {
            $this->repository->attachToContent($contentId, $mediaIds);
            $this->afterAttachToContent($contentId, $mediaIds);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new ServiceException("Error attaching media to content: {$e->getMessage()}");
        }
    }

    protected function afterAttachToContent(int $contentId, array $mediaIds): void
    {
        $this->clearCache();
        Cache::tags(['content'])->flush();
        event(new MediaAttachedToContent($contentId, $mediaIds));
    }
}
