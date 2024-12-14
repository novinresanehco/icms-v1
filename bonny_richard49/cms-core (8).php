<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Content\DTO\{ContentRequest, ContentResponse};
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\{Cache, DB, Log};

class ContentManagementService implements ContentManagementInterface
{
    private ContentRepository $repository;
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private MediaService $media;
    private CacheService $cache;

    public function __construct(
        ContentRepository $repository,
        SecurityManagerInterface $security,
        ValidationService $validator,
        MediaService $media,
        CacheService $cache
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->media = $media;
        $this->cache = $cache;
    }

    public function create(ContentRequest $request): ContentResponse
    {
        return $this->executeSecureOperation(function() use ($request) {
            // Validate content
            $validated = $this->validator->validateContent($request);
            
            // Process media if present
            if ($request->hasMedia()) {
                $validated = $this->processMedia($validated);
            }
            
            // Create content with versioning
            $content = DB::transaction(function() use ($validated) {
                $content = $this->repository->create($validated);
                $this->repository->createVersion($content->id, 1);
                return $content;
            });
            
            // Cache the created content
            $this->cacheContent($content);
            
            return new ContentResponse($content);
        }, 'content.create');
    }

    public function update(int $id, ContentRequest $request): ContentResponse
    {
        return $this->executeSecureOperation(function() use ($id, $request) {
            // Validate update request
            $validated = $this->validator->validateContent($request);
            
            // Update with versioning
            $content = DB::transaction(function() use ($id, $validated) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }
                
                // Create new version
                $version = $content->version + 1;
                $this->repository->createVersion($id, $version);
                
                // Update content
                return $this->repository->update($id, $validated);
            });
            
            // Update cache
            $this->cache->invalidate($this->getCacheKey($id));
            $this->cacheContent($content);
            
            return new ContentResponse($content);
        }, 'content.update');
    }

    public function publish(int $id): ContentResponse
    {
        return $this->executeSecureOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $content = $this->repository->publish($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }
                
                // Update cache with published status
                $this->cache->invalidate($this->getCacheKey($id));
                $this->cacheContent($content);
                
                return new ContentResponse($content);
            });
        }, 'content.publish');
    }

    public function delete(int $id): void
    {
        $this->executeSecureOperation(function() use ($id) {
            DB::transaction(function() use ($id) {
                // Soft delete content and invalidate cache
                $this->repository->delete($id);
                $this->cache->invalidate($this->getCacheKey($id));
                
                // Clean up associated media
                $this->media->cleanupContentMedia($id);
            });
        }, 'content.delete');
    }

    public function find(int $id): ContentResponse
    {
        return $this->executeSecureOperation(function() use ($id) {
            // Try to get from cache first
            $cacheKey = $this->getCacheKey($id);
            
            return $this->cache->remember($cacheKey, function() use ($id) {
                $content = $this->repository->find($id);
                
                if (!$content) {
                    throw new ContentException('Content not found');
                }
                
                return new ContentResponse($content);
            });
        }, 'content.read');
    }

    private function executeSecureOperation(callable $operation, string $permission): mixed
    {
        try {
            // Validate security context and permissions
            $context = $this->createSecurityContext($permission);
            $this->security->validateCriticalOperation($context);
            
            // Execute the operation
            return $operation();
            
        } catch (\Exception $e) {
            Log::error('Content operation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new ContentException(
                'Content operation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function processMedia(array $validated): array
    {
        foreach ($validated['media'] ?? [] as $key => $media) {
            $processed = $this->media->process($media);
            $validated['media'][$key] = $processed->id;
        }
        return $validated;
    }

    private function cacheContent($content): void
    {
        $this->cache->put(
            $this->getCacheKey($content->id),
            $content,
            config('cache.content_ttl')
        );
    }

    private function getCacheKey(int $id): string
    {
        return "content.{$id}";
    }

    private function createSecurityContext(string $permission): SecurityContext
    {
        return new SecurityContext([
            'permission' => $permission,
            'resource' => 'content',
            'ip' => request()->ip(),
            'user' => auth()->user()
        ]);
    }
}
