<?php

namespace App\Core\Services;

use App\Core\Repository\BaseRepository;
use App\Core\Exceptions\ServiceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

abstract class BaseService
{
    protected BaseRepository $repository;
    protected array $validationRules = [];
    protected array $callbacks = [];
    protected bool $useTransactions = true;
    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'service',
        'tags' => []
    ];

    public function __construct(BaseRepository $repository)
    {
        $this->repository = $repository;
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cacheConfig['prefix'],
            strtolower(class_basename($this)),
            $key
        );
    }

    protected function validateData(array $data, ?array $rules = null): bool
    {
        $rules = $rules ?? $this->validationRules;
        
        $validator = validator($data, $rules);
        
        if ($validator->fails()) {
            throw new ServiceException(
                "Validation failed: " . implode(", ", $validator->errors()->all())
            );
        }

        return true;
    }

    public function find(int $id): ?Model
    {
        try {
            return $this->repository->find($id);
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
            return null;
        }
    }

    public function findOrFail(int $id): Model
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

    public function all(): Collection
    {
        try {
            return $this->repository->all();
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

    public function create(array $data): Model
    {
        try {
            // Validate data
            $this->validateData($data);

            // Begin transaction if enabled
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            // Run pre-create callbacks
            $this->runCallbacks('creating', $data);

            // Create model
            $model = $this->repository->create($data);

            // Run post-create callbacks
            $this->runCallbacks('created', $model);

            // Commit transaction
            if ($this->useTransactions) {
                DB::commit();
            }

            // Clear relevant caches
            $this->clearServiceCache();

            return $model;
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            // Validate data
            $this->validateData($data);

            // Begin transaction if enabled
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            // Run pre-update callbacks
            $this->runCallbacks('updating', [$id, $data]);

            // Update model
            $model = $this->repository->update($id, $data);

            // Run post-update callbacks
            $this->runCallbacks('updated', $model);

            // Commit transaction
            if ($this->useTransactions) {
                DB::commit();
            }

            // Clear relevant caches
            $this->clearServiceCache();

            return $model;
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }

    public function delete(int $id): bool
    {
        try {
            // Begin transaction if enabled
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            // Run pre-delete callbacks
            $this->runCallbacks('deleting', $id);

            // Delete model
            $result = $this->repository->delete($id);

            // Run post-delete callbacks
            $this->runCallbacks('deleted', $id);

            // Commit transaction
            if ($this->useTransactions) {
                DB::commit();
            }

            // Clear relevant caches
            $this->clearServiceCache();

            return $result;
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }

    protected function runCallbacks(string $event, $data): void
    {
        if (isset($this->callbacks[$event])) {
            foreach ($this->callbacks[$event] as $callback) {
                $callback($data);
            }
        }
    }

    protected function registerCallback(string $event, callable $callback): self
    {
        $this->callbacks[$event][] = $callback;
        return $this;
    }

    protected function clearServiceCache(): void
    {
        if (!empty($this->cacheConfig['tags'])) {
            Cache::tags($this->cacheConfig['tags'])->flush();
        }
    }

    protected function handleException(Exception $e, string $method): void
    {
        Log::error(sprintf(
            '[%s::%s] %s',
            class_basename($this),
            $method,
            $e->getMessage()
        ), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        throw new ServiceException(
            "Operation failed in {$method}: {$e->getMessage()}",
            $e->getCode(),
            $e
        );
    }
}

class ContentService extends BaseService
{
    protected array $validationRules = [
        'title' => 'required|string|max:255',
        'slug' => 'required|string|unique:contents,slug',
        'content' => 'required|string',
        'status' => 'required|in:draft,published,archived'
    ];

    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'content',
        'tags' => ['content']
    ];

    public function publish(int $id): Model
    {
        try {
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            $content = $this->repository->findOrFail($id);
            
            if ($content->status === 'published') {
                throw new ServiceException("Content is already published");
            }

            $result = $this->repository->update($id, [
                'status' => 'published',
                'published_at' => now()
            ]);

            if ($this->useTransactions) {
                DB::commit();
            }

            $this->clearServiceCache();

            return $result;
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }

    public function findPublished(): Collection
    {
        try {
            $cacheKey = $this->getCacheKey('published');

            return Cache::tags($this->cacheConfig['tags'])
                ->remember($cacheKey, $this->cacheConfig['ttl'], function() {
                    return $this->repository->findPublished();
                });
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }
}

class TagService extends BaseService
{
    protected array $validationRules = [
        'name' => 'required|string|max:50|unique:tags,name',
        'slug' => 'required|string|unique:tags,slug',
        'description' => 'nullable|string'
    ];

    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'tag',
        'tags' => ['tag']
    ];

    public function findOrCreateByName(string $name): Model
    {
        try {
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            $tag = $this->repository->findByName($name);

            if (!$tag) {
                $tag = $this->create([
                    'name' => $name,
                    'slug' => str_slug($name)
                ]);
            }

            if ($this->useTransactions) {
                DB::commit();
            }

            return $tag;
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        try {
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            $this->repository->attachToContent($contentId, $tagIds);

            if ($this->useTransactions) {
                DB::commit();
            }

            $this->clearServiceCache();
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }
}

class MediaService extends BaseService
{
    protected array $validationRules = [
        'title' => 'required|string|max:255',
        'type' => 'required|string|in:image,video,document',
        'path' => 'required|string',
        'size' => 'required|integer',
        'mime_type' => 'required|string'
    ];

    protected array $cacheConfig = [
        'ttl' => 3600,
        'prefix' => 'media',
        'tags' => ['media']
    ];

    public function upload(UploadedFile $file, array $data = []): Model
    {
        try {
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            // Handle file upload
            $path = $file->store('media', 'public');

            // Create media record
            $media = $this->create(array_merge($data, [
                'path' => $path,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'type' => $this->determineMediaType($file)
            ]));

            if ($this->useTransactions) {
                DB::commit();
            }

            return $media;
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }

    protected function determineMediaType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        
        if (strpos($mime, 'image/') === 0) {
            return 'image';
        }
        
        if (strpos($mime, 'video/') === 0) {
            return 'video';
        }
        
        return 'document';
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        try {
            if ($this->useTransactions) {
                DB::beginTransaction();
            }

            $this->repository->attachToContent($contentId, $mediaIds);

            if ($this->useTransactions) {
                DB::commit();
            }

            $this->clearServiceCache();
        } catch (Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            $this->handleException($e, __FUNCTION__);
        }
    }
}
