<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Repository\ContentRepository;
use App\Core\Services\MediaService;
use App\Core\Events\ContentEvent;
use App\Core\Exceptions\{
    ContentException,
    ValidationException,
    SecurityException
};

class ContentService
{
    protected ContentRepository $repository;
    protected SecurityManager $security;
    protected MediaService $mediaService;
    protected array $config;
    protected int $cacheTimeout;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        MediaService $mediaService
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->mediaService = $mediaService;
        $this->config = config('cms.content');
        $this->cacheTimeout = config('cache.ttl', 3600);
    }

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // Create security context
            $context = $this->createSecurityContext('create', $data);
            
            try {
                // Validate operation
                $this->security->validateOperation($context);
                
                // Process media if present
                if (isset($data['media'])) {
                    $data['media'] = $this->processMedia($data['media']);
                }
                
                // Create content
                $content = $this->repository->create($data);
                
                // Process content
                $processed = $this->processContent($content);
                
                // Verify result
                $this->security->verifyResult($processed, $context);
                
                // Clear relevant caches
                $this->clearContentCaches($content->id);
                
                // Dispatch event
                event(new ContentEvent('created', $content));
                
                return $processed;
                
            } catch (\Exception $e) {
                $this->handleException($e, $context);
                throw $e;
            }
        });
    }

    public function update(int $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data) {
            $context = $this->createSecurityContext('update', ['id' => $id] + $data);
            
            try {
                // Validate operation
                $this->security->validateOperation($context);
                
                // Process media updates
                if (isset($data['media'])) {
                    $data['media'] = $this->processMediaUpdates($id, $data['media']);
                }
                
                // Update content
                $content = $this->repository->update($id, $data);
                
                // Process content
                $processed = $this->processContent($content);
                
                // Verify result
                $this->security->verifyResult($processed, $context);
                
                // Clear caches
                $this->clearContentCaches($id);
                
                // Dispatch event
                event(new ContentEvent('updated', $content));
                
                return $processed;
                
            } catch (\Exception $e) {
                $this->handleException($e, $context);
                throw $e;
            }
        });
    }

    public function get(int $id, array $options = []): array
    {
        $cacheKey = $this->getCacheKey('content', $id, $options);
        
        return Cache::remember($cacheKey, $this->cacheTimeout, function () use ($id, $options) {
            $context = $this->createSecurityContext('get', ['id' => $id] + $options);
            
            try {
                // Validate operation
                $this->security->validateOperation($context);
                
                // Get content
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException("Content not found: {$id}");
                }
                
                // Process content
                $processed = $this->processContent($content, $options);
                
                // Verify result
                $this->security->verifyResult($processed, $context);
                
                return $processed;
                
            } catch (\Exception $e) {
                $this->handleException($e, $context);
                throw $e;
            }
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $context = $this->createSecurityContext('delete', ['id' => $id]);
            
            try {
                // Validate operation
                $this->security->validateOperation($context);
                
                // Get content for event
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException("Content not found: {$id}");
                }
                
                // Delete media
                $this->mediaService->deleteForContent($id);
                
                // Delete content
                $result = $this->repository->delete($id);
                
                // Verify result
                $this->security->verifyResult($result, $context);
                
                // Clear caches
                $this->clearContentCaches($id);
                
                // Dispatch event
                event(new ContentEvent('deleted', $content));
                
                return $result;
                
            } catch (\Exception $e) {
                $this->handleException($e, $context);
                throw $e;
            }
        });
    }

    protected function processContent($content, array $options = []): array
    {
        $processed = $content->toArray();
        
        // Process media
        if (isset($processed['media'])) {
            $processed['media'] = $this->mediaService->processMediaItems(
                $processed['media'],
                $options['media'] ?? []
            );
        }
        
        // Apply content transformations
        $processed = $this->applyContentTransformations($processed, $options);
        
        // Apply security filters
        return $this->applySecurityFilters($processed);
    }

    protected function processMedia(array $media): array
    {
        return array_map(function ($item) {
            return $this->mediaService->process($item);
        }, $media);
    }

    protected function processMediaUpdates(int $contentId, array $media): array
    {
        // Process media deletions
        if (isset($media['delete'])) {
            $this->mediaService->deleteItems($contentId, $media['delete']);
        }
        
        // Process media updates
        if (isset($media['update'])) {
            $this->mediaService->updateItems($contentId, $media['update']);
        }
        
        // Process new media
        if (isset($media['create'])) {
            return $this->processMedia($media['create']);
        }
        
        return [];
    }

    protected function createSecurityContext(string $operation, array $data): array
    {
        return [
            'operation' => $operation,
            'service' => self::class,
            'data' => $data,
            'timestamp' => now(),
            'user_id' => auth()->id()
        ];
    }

    protected function getCacheKey(string $type, int $id, array $options = []): string
    {
        return sprintf(
            'cms:%s:%d:%s',
            $type,
            $id,
            md5(serialize($options))
        );
    }

    protected function clearContentCaches(int $id): void
    {
        Cache::tags(['cms', "content:{$id}"])->flush();
    }

    protected function handleException(\Exception $e, array $context): void
    {
        Log::error('Content operation failed', [
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function applyContentTransformations(array $content, array $options): array
    {
        // Apply content type specific transformations
        if (isset($content['type'])) {
            $content = $this->applyTypeTransformations($content, $content['type']);
        }
        
        // Apply requested transformations
        if (isset($options['transform'])) {
            foreach ($options['transform'] as $transformation) {
                $content = $this->applyTransformation($content, $transformation);
            }
        }
        
        return $content;
    }

    protected function applySecurityFilters(array $content): array
    {
        // Remove sensitive fields
        foreach ($this->config['sensitive_fields'] as $field) {
            unset($content[$field]);
        }
        
        // Apply field-level security
        foreach ($content as $field => $value) {
            if (!$this->security->canAccessField($field)) {
                unset($content[$field]);
            }
        }
        
        return $content;
    }

    protected function applyTypeTransformations(array $content, string $type): array
    {
        $transformer = $this->getTypeTransformer($type);
        return $transformer ? $transformer->transform($content) : $content;
    }

    protected function applyTransformation(array $content, string $transformation): array
    {
        $transformer = $this->getTransformer($transformation);
        return $transformer ? $transformer->transform($content) : $content;
    }

    protected function getTypeTransformer(string $type)
    {
        // Implementation depends on transformer registry
        return null;
    }

    protected function getTransformer(string $name)
    {
        // Implementation depends on transformer registry
        return null;
    }
}
