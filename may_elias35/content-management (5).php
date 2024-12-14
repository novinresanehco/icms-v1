<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Storage\StorageManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\ContentException;

class ContentManager implements ContentInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageManager $storage;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        StorageManager $storage,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function createContent(array $data): Content
    {
        $monitoringId = $this->monitor->startOperation('content_create');
        
        try {
            $this->validateContentData($data);
            
            DB::beginTransaction();
            
            $content = $this->prepareContent($data);
            $content->save();
            
            $this->processMedia($content, $data['media'] ?? []);
            $this->processMetadata($content, $data['metadata'] ?? []);
            
            $this->createVersion($content);
            $this->updateCache($content);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function updateContent(int $id, array $data): Content
    {
        $monitoringId = $this->monitor->startOperation('content_update');
        
        try {
            $content = Content::findOrFail($id);
            
            $this->validateContentData($data);
            $this->validateContentAccess($content, 'update');
            
            DB::beginTransaction();
            
            $this->createBackup($content);
            
            $content = $this->updateContentData($content, $data);
            $content->save();
            
            $this->processMedia($content, $data['media'] ?? []);
            $this->processMetadata($content, $data['metadata'] ?? []);
            
            $this->createVersion($content);
            $this->updateCache($content);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function deleteContent(int $id): bool
    {
        $monitoringId = $this->monitor->startOperation('content_delete');
        
        try {
            $content = Content::findOrFail($id);
            
            $this->validateContentAccess($content, 'delete');
            
            DB::beginTransaction();
            
            $this->createBackup($content);
            $this->cleanupMedia($content);
            $this->cleanupMetadata($content);
            
            $content->delete();
            
            $this->clearCache($content);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ContentException('Content deletion failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function publishContent(int $id): bool
    {
        $monitoringId = $this->monitor->startOperation('content_publish');
        
        try {
            $content = Content::findOrFail($id);
            
            $this->validateContentAccess($content, 'publish');
            $this->validatePublishingRequirements($content);
            
            DB::beginTransaction();
            
            $content->published_at = now();
            $content->status = 'published';
            $content->save();
            
            $this->createVersion($content);
            $this->updateCache($content);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ContentException('Content publishing failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateContentData(array $data): void
    {
        if (!$this->validator->validateContent($data)) {
            throw new ContentException('Invalid content data');
        }

        if (!$this->security->validateContentSecurity($data)) {
            throw new ContentException('Content security validation failed');
        }
    }

    private function validateContentAccess(Content $content, string $action): void
    {
        if (!$this->security->validateContentAccess($content, $action)) {
            throw new ContentException('Access denied');
        }
    }

    private function prepareContent(array $data): Content
    {
        $content = new Content();
        
        $content->fill($this->sanitizeContentData($data));
        $content->user_id = auth()->id();
        $content->status = 'draft';
        
        return $content;
    }

    private function processMedia(Content $content, array $media): void
    {
        foreach ($media as $item) {
            if (!$this->validateMediaItem($item)) {
                throw new ContentException('Invalid media item');
            }
            
            $this->storage->storeMedia($content->id, $item);
        }
    }

    private function processMetadata(Content $content, array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (!$this->validateMetadata($key, $value)) {
                throw new ContentException('Invalid metadata');
            }
            
            $content->metadata()->create([
                'key' => $key,
                'value' => $value
            ]);
        }
    }

    private function createVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'user_id' => auth()->id(),
            'created_at' => now()
        ]);
    }

    private function updateCache(Content $content): void
    {
        Cache::tags(['content'])
             ->put("content.{$content->id}", $content, $this->config['cache_ttl']);
    }

    private function createBackup(Content $content): void
    {
        $this->storage->createBackup(
            "content_{$content->id}",
            $content->toArray()
        );
    }

    private function cleanupMedia(Content $content): void
    {
        foreach ($content->media as $media) {
            $this->storage->deleteMedia($media->id);
        }
    }

    private function cleanupMetadata(Content $content): void
    {
        $content->metadata()->delete();
    }

    private function clearCache(Content $content): void
    {
        Cache::tags(['content'])->forget("content.{$content->id}");
    }

    private function validatePublishingRequirements(Content $content): void
    {
        if (!$this->validateRequiredFields($content)) {
            throw new ContentException('Required fields missing');
        }

        if (!$this->validateContentQuality($content)) {
            throw new ContentException('Content quality check failed');
        }

        if (!$this->validateWorkflowState($content)) {
            throw new ContentException('Invalid workflow state for publishing');
        }
    }

    private function sanitizeContentData(array $data): array
    {
        return array_map(function ($item) {
            if (is_string($item)) {
                return $this->sanitizeString($item);
            }
            if (is_array($item)) {
                return $this->sanitizeContentData($item);
            }
            return $item;
        }, $data);
    }

    private function sanitizeString(string $value): string
    {
        $value = strip_tags($value, $this->config['allowed_tags']);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $value;
    }

    private function validateMediaItem(array $item): bool
    {
        return isset($item['type']) && 
               in_array($item['type'], $this->config['allowed_media_types']) &&
               isset($item['file']) &&
               $item['file'] instanceof UploadedFile;
    }

    private function validateMetadata(string $key, $value): bool
    {
        return in_array($key, $this->config['allowed_metadata_keys']) &&
               $this->validateMetadataValue($value);
    }

    private function validateMetadataValue($value): bool
    {
        return is_scalar($value) || is_array($value);
    }

    private function validateRequiredFields(Content $content): bool
    {
        foreach ($this->config['required_fields'] as $field) {
            if (empty($content->$field)) {
                return false;
            }
        }
        return true;
    }

    private function validateContentQuality(Content $content): bool
    {
        return $this->validateLength($content) &&
               $this->validateFormat($content) &&
               $this->validateSEO($content);
    }

    private function validateWorkflowState(Content $content): bool
    {
        return in_array($content->status, ['draft', 'reviewed']);
    }
}
