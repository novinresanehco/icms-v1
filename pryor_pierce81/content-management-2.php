<?php

namespace App\Core\Content;

use App\Core\Service\BaseService;
use App\Core\Content\Models\Content;
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\ContentException;
use App\Core\Support\{Validator, MediaHandler};

class ContentService extends BaseService
{
    protected MediaHandler $mediaHandler;
    protected Validator $validator;
    
    public function create(array $data): Content
    {
        return $this->executeSecureOperation('create', $data, function() use ($data) {
            return $this->executeInTransaction(function() use ($data) {
                // Validate content data
                $validated = $this->validateOperation('create', $data);
                
                // Handle media attachments
                $media = $this->processMedia($data['media'] ?? []);
                
                // Create content
                $content = $this->repository->create([
                    ...$validated,
                    'user_id' => auth()->id(),
                    'status' => ContentStatus::DRAFT,
                    'version' => 1,
                ]);
                
                // Attach media
                if ($media) {
                    $content->attachMedia($media);
                }
                
                // Process content variants
                $this->processContentVariants($content, $data['variants'] ?? []);
                
                // Update search index
                $this->updateSearchIndex($content);
                
                // Clear relevant caches
                $this->clearContentCaches($content);
                
                return $content;
            });
        });
    }
    
    public function update(int $id, array $data): Content 
    {
        return $this->executeSecureOperation('update', $data, function() use ($id, $data) {
            return $this->executeInTransaction(function() use ($id, $data) {
                // Get existing content
                $content = $this->repository->findOrFail($id);
                
                // Validate update data
                $validated = $this->validateOperation('update', $data);
                
                // Create new version if needed
                if ($this->shouldCreateVersion($content, $validated)) {
                    $this->createContentVersion($content);
                }
                
                // Update content
                $content = $this->repository->update($id, [
                    ...$validated,
                    'version' => $content->version + 1,
                ]);
                
                // Handle media updates
                if (isset($data['media'])) {
                    $this->updateContentMedia($content, $data['media']);
                }
                
                // Update variants
                if (isset($data['variants'])) {
                    $this->updateContentVariants($content, $data['variants']);
                }
                
                // Update search index
                $this->updateSearchIndex($content);
                
                // Clear caches
                $this->clearContentCaches($content);
                
                return $content;
            });
        });
    }
    
    public function publish(int $id): Content
    {
        return $this->executeSecureOperation('publish', ['id' => $id], function() use ($id) {
            return $this->executeInTransaction(function() use ($id) {
                // Get content
                $content = $this->repository->findOrFail($id);
                
                // Validate publishable
                $this->validatePublishable($content);
                
                // Update status
                $content = $this->repository->update($id, [
                    'status' => ContentStatus::PUBLISHED,
                    'published_at' => now(),
                ]);
                
                // Clear caches
                $this->clearContentCaches($content);
                
                // Dispatch events
                $this->events->dispatch(new ContentPublished($content));
                
                return $content;
            });
        });
    }
    
    protected function validatePublishable(Content $content): void
    {
        if (!$content->isPublishable()) {
            throw new ContentException("Content #{$content->id} is not publishable");
        }
    }
    
    protected function shouldCreateVersion(Content $content, array $data): bool
    {
        return array_intersect(array_keys($data), [
            'title',
            'content',
            'meta',
        ]);
    }
    
    protected function createContentVersion(Content $content): void
    {
        $this->repository->createVersion([
            'content_id' => $content->id,
            'version' => $content->version,
            'data' => $content->toArray(),
            'user_id' => auth()->id(),
        ]);
    }
    
    protected function processMedia(array $media): array
    {
        return array_map(function($item) {
            return $this->mediaHandler->process($item);
        }, $media);
    }
    
    protected function updateContentMedia(Content $content, array $media): void
    {
        // Remove old media
        $content->detachMedia();
        
        // Process and attach new media
        $processed = $this->processMedia($media);
        $content->attachMedia($processed);
    }
    
    protected function processContentVariants(Content $content, array $variants): void
    {
        foreach ($variants as $variant) {
            $content->variants()->create($variant);
        }
    }
    
    protected function updateContentVariants(Content $content, array $variants): void
    {
        // Remove old variants
        $content->variants()->delete();
        
        // Create new variants
        $this->processContentVariants($content, $variants);
    }
    
    protected function updateSearchIndex(Content $content): void
    {
        if ($content->isPublished()) {
            $this->search->index($content);
        } else {
            $this->search->remove($content);
        }
    }
    
    protected function clearContentCaches(Content $content): void
    {
        $this->cache->tags(['content', "content:{$content->id}"])->flush();
    }
    
    protected function getValidationRules(string $operation): array
    {
        return match($operation) {
            'create' => [
                'title' => ['required', 'string', 'max:255'],
                'content' => ['required', 'string'],
                'meta' => ['array'],
                'media' => ['array'],
                'variants' => ['array'],
            ],
            'update' => [
                'title' => ['string', 'max:255'],
                'content' => ['string'],
                'meta' => ['array'],
                'media' => ['array'],
                'variants' => ['array'],
            ],
            default => []
        };
    }
}
