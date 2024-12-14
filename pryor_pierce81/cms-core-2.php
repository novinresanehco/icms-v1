<?php

namespace App\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\{
    RepositoryInterface,
    SecurityManagerInterface,
    ValidationServiceInterface
};

abstract class BaseRepository implements RepositoryInterface 
{
    protected $model;
    protected $cache;
    protected $security;
    protected $validator;

    public function __construct(
        $model,
        $cache,
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
    }

    public function find($id)
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            config('cache.ttl'),
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data)
    {
        return DB::transaction(function() use ($data) {
            // Validate and sanitize input
            $validated = $this->validator->validate($data);
            
            // Create with security checks
            $created = $this->model->create($validated);
            
            // Clear relevant cache
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $created;
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function() use ($id, $data) {
            $model = $this->model->findOrFail($id);
            
            // Security and validation
            $this->security->checkAccess('update', $model);
            $validated = $this->validator->validate($data);
            
            $model->update($validated);
            
            // Clear cache
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $model;
        });
    }

    public function delete($id)
    {
        return DB::transaction(function() use ($id) {
            $model = $this->model->findOrFail($id);
            
            $this->security->checkAccess('delete', $model);
            $model->delete();
            
            $this->cache->tags($this->getCacheTags())->flush();
            
            return true;
        });
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            get_class($this->model),
            $operation,
            implode(':', $params)
        );
    }

    protected function getCacheTags(): array
    {
        return [get_class($this->model)];
    }

    protected function fireCriticalOperation(string $operation, callable $callback)
    {
        return DB::transaction(function() use ($operation, $callback) {
            // Pre-execution security check
            $this->security->validateAccess($operation);
            
            try {
                $result = $callback();
                
                // Post-execution validation
                $this->validator->validateResult($result);
                
                return $result;
                
            } catch (\Exception $e) {
                report($e);
                throw new OperationException(
                    "Critical operation failed: {$operation}",
                    0,
                    $e
                );
            }
        });
    }
}

// Content Repository Implementation
class ContentRepository extends BaseRepository
{
    const CACHE_TTL = 3600;

    public function getLatest(int $limit = 10)
    {
        return $this->cache->remember(
            $this->getCacheKey('latest', $limit),
            self::CACHE_TTL,
            fn() => $this->model->latest()->take($limit)->get()
        );
    }

    public function getByCategory($categoryId)
    {
        return $this->cache->remember(
            $this->getCacheKey('category', $categoryId),
            self::CACHE_TTL,
            fn() => $this->model->whereCategoryId($categoryId)->get()
        );
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|max:200',
            'content' => 'required',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|in:draft,published'
        ];
    }
}

// Media Repository Implementation
class MediaRepository extends BaseRepository
{
    public function store(UploadedFile $file): Media
    {
        return $this->fireCriticalOperation('store_media', function() use ($file) {
            // Validate file
            $this->validator->validateFile($file);
            
            // Store with security checks
            $path = $file->store('media', 'secure');
            
            return $this->create([
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize()
            ]);
        });
    }

    protected function getValidationRules(): array
    {
        return [
            'path' => 'required|string',
            'mime_type' => 'required|string',
            'size' => 'required|integer'
        ];
    }
}
