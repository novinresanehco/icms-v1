<?php

namespace App\Core\Repository;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

abstract class CriticalRepository
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected array $config;
    
    abstract protected function model(): string;
    abstract protected function rules(): array;
    
    protected function executeSecure(callable $operation): mixed
    {
        DB::beginTransaction();
        
        try {
            $result = $operation();
            $this->validateResult($result);
            DB::commit();
            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function create(array $data): mixed
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validator->validate($data, $this->rules());
            $model = $this->model()::create($validated);
            $this->cache->invalidateTag($this->cacheTag());
            return $model;
        });
    }

    protected function update(int $id, array $data): mixed
    {
        return $this->executeSecure(function() use ($id, $data) {
            $model = $this->findOrFail($id);
            $validated = $this->validator->validate($data, $this->rules());
            $model->update($validated);
            $this->cache->invalidateTag($this->cacheTag());
            return $model;
        });
    }

    protected function delete(int $id): bool
    {
        return $this->executeSecure(function() use ($id) {
            $model = $this->findOrFail($id);
            $result = $model->delete();
            $this->cache->invalidateTag($this->cacheTag());
            return $result;
        });
    }

    protected function find(int $id): mixed
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            fn() => $this->model()::find($id)
        );
    }

    protected function findOrFail(int $id): mixed
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new ModelNotFoundException("Model not found: $id");
        }

        return $model;
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function getCacheKey(int $id): string
    {
        return sprintf('%s.%d', $this->cacheTag(), $id);
    }

    private function cacheTag(): string
    {
        return strtolower(class_basename($this->model()));
    }
}

final class ContentRepository extends CriticalRepository
{
    protected function model(): string 
    {
        return Content::class;
    }

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'user_id' => 'required|exists:users,id'
        ];
    }

    public function findPublished(int $id): ?Content
    {
        return $this->cache->remember(
            "content.published.$id",
            fn() => $this->model()::published()->find($id)
        );
    }
}

final class MediaRepository extends CriticalRepository
{
    protected function model(): string
    {
        return Media::class;
    }

    protected function rules(): array
    {
        return [
            'type' => 'required|in:image,video,document',
            'path' => 'required|string',
            'size' => 'required|integer|max:' . $this->config['max_size'],
            'mime_type' => 'required|string'
        ];
    }

    public function findByType(string $type): Collection
    {
        return $this->cache->remember(
            "media.type.$type",
            fn() => $this->model()::whereType($type)->get()
        );
    }
}

class ModelNotFoundException extends \Exception {}
class ValidationException extends \Exception {}
