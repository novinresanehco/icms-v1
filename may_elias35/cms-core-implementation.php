<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function create(array $data): Content
    {
        return $this->executeSecureOperation(function() use ($data) {
            $validated = $this->validator->validate($data, $this->createRules());
            $content = $this->repository->create($validated);
            $this->cache->invalidate(['content', $content->id]);
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return $this->executeSecureOperation(function() use ($id, $data) {
            $content = $this->repository->findOrFail($id);
            $validated = $this->validator->validate($data, $this->updateRules($id));
            $updated = $this->repository->update($content, $validated);
            $this->cache->invalidate(['content', $id]);
            return $updated;
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecureOperation(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            $result = $this->repository->delete($content);
            $this->cache->invalidate(['content', $id]);
            return $result;
        });
    }

    public function publish(int $id): bool
    {
        return $this->executeSecureOperation(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            $result = $this->repository->publish($content);
            $this->cache->invalidate(['content', $id]);
            return $result;
        });
    }

    private function executeSecureOperation(callable $operation)
    {
        return DB::transaction(function() use ($operation) {
            return $operation();
        });
    }

    private function createRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id'
        ];
    }

    private function updateRules(int $id): array
    {
        return [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id'
        ];
    }
}

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private StorageService $storage;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->validator = $validator;
    }

    public function upload(UploadedFile $file): Media
    {
        return $this->executeSecureOperation(function() use ($file) {
            $validated = $this->validator->validateFile($file, $this->uploadRules());
            $path = $this->storage->store($validated);
            return Media::create(['path' => $path]);
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecureOperation(function() use ($id) {
            $media = Media::findOrFail($id);
            $this->storage->delete($media->path);
            return $media->delete();
        });
    }

    private function uploadRules(): array
    {
        return [
            'file' => 'required|file|mimes:jpeg,png,pdf|max:10240'
        ];
    }

    private function executeSecureOperation(callable $operation)
    {
        return DB::transaction(function() use ($operation) {
            return $operation();
        });
    }
}

class CategoryManager implements CategoryManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function create(array $data): Category
    {
        return $this->executeSecureOperation(function() use ($data) {
            $validated = $this->validator->validate($data, $this->createRules());
            $category = $this->repository->create($validated);
            $this->cache->invalidate(['categories']);
            return $category;
        });
    }

    public function update(int $id, array $data): Category
    {
        return $this->executeSecureOperation(function() use ($id, $data) {
            $category = $this->repository->findOrFail($id);
            $validated = $this->validator->validate($data, $this->updateRules($id));
            $updated = $this->repository->update($category, $validated);
            $this->cache->invalidate(['categories', $id]);
            return $updated;
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecureOperation(function() use ($id) {
            $category = $this->repository->findOrFail($id);
            $result = $this->repository->delete($category);
            $this->cache->invalidate(['categories', $id]);
            return $result;
        });
    }

    private function createRules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:categories',
            'slug' => 'required|string|max:255|unique:categories',
            'description' => 'string|nullable'
        ];
    }

    private function updateRules(int $id): array
    {
        return [
            'name' => "string|max:255|unique:categories,name,{$id}",
            'slug' => "string|max:255|unique:categories,slug,{$id}",
            'description' => 'string|nullable'
        ];
    }

    private function executeSecureOperation(callable $operation)
    {
        return DB::transaction(function() use ($operation) {
            return $operation();
        });
    }
}

class CacheService implements CacheServiceInterface
{
    private CacheManager $cache;
    private array $config;

    public function __construct(CacheManager $cache, array $config)
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return $this->cache->remember($key, $ttl ?? $this->config['ttl'], $callback);
    }

    public function forget(string $key): bool
    {
        return $this->cache->forget($key);
    }

    public function tags(array $tags): self
    {
        $this->cache->tags($tags);
        return $this;
    }
}

interface ContentManagerInterface
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function publish(int $id): bool;
}

interface MediaManagerInterface
{
    public function upload(UploadedFile $file): Media;
    public function delete(int $id): bool;
}

interface CategoryManagerInterface
{
    public function create(array $data): Category;
    public function update(int $id, array $data): Category;
    public function delete(int $id): bool;
}

interface CacheServiceInterface
{
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
    public function forget(string $key): bool;
    public function tags(array $tags): self;
}
