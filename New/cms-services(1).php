<?php

namespace App\Core\Services;

use App\Core\Security\SecurityManager;
use App\Core\Repositories\{ContentRepository, MediaRepository};
use App\Core\Interfaces\CmsServiceInterface;
use Illuminate\Support\Facades\{Cache, Log};

class ContentService implements CmsServiceInterface 
{
    protected ContentRepository $content;
    protected MediaRepository $media;
    protected SecurityManager $security;
    protected array $cacheConfig;

    public function __construct(
        ContentRepository $content,
        MediaRepository $media,
        SecurityManager $security
    ) {
        $this->content = $content;
        $this->media = $media;
        $this->security = $security;
        $this->cacheConfig = config('cache.content');
    }

    public function create(array $data): array
    {
        return $this->security->executeSecure(function() use ($data) {
            // Validate and create content
            $content = $this->content->create($data);
            
            // Handle media attachments
            if (isset($data['media'])) {
                $this->attachMedia($content, $data['media']);
            }

            // Clear relevant caches
            $this->clearContentCaches($content->id);
            
            return $content->toArray();
        }, ['action' => 'content.update', 'content_id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecure(function() use ($id) {
            // Delete content and related media
            $this->content->delete($id);
            
            // Clear all related caches
            $this->clearContentCaches($id);
            
            return true;
        }, ['action' => 'content.delete', 'content_id' => $id]);
    }

    public function publish(int $id): array
    {
        return $this->security->executeSecure(function() use ($id) {
            $content = $this->content->publish($id);
            $this->clearContentCaches($id);
            return $content->toArray();
        }, ['action' => 'content.publish', 'content_id' => $id]);
    }

    protected function attachMedia($content, array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            $this->security->executeSecure(function() use ($content, $mediaId) {
                $media = $this->media->findOrFail($mediaId);
                $content->media()->attach($media->id);
            }, ['action' => 'media.attach', 'content_id' => $content->id, 'media_id' => $mediaId]);
        }
    }

    protected function syncMedia($content, array $mediaIds): void 
    {
        $this->security->executeSecure(function() use ($content, $mediaIds) {
            $content->media()->sync($mediaIds);
        }, ['action' => 'media.sync', 'content_id' => $content->id]);
    }

    protected function clearContentCaches(int $contentId): void
    {
        $cacheKeys = [
            "content.{$contentId}",
            "content.{$contentId}.media",
            'content.recent',
            'content.published'
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }
}

class MediaService
{
    protected MediaRepository $media;
    protected SecurityManager $security;
    protected array $allowedTypes;
    protected int $maxFileSize;

    public function __construct(
        MediaRepository $media,
        SecurityManager $security
    ) {
        $this->media = $media;
        $this->security = $security;
        $this->allowedTypes = config('cms.media.allowed_types');
        $this->maxFileSize = config('cms.media.max_size');
    }

    public function upload(array $file): array
    {
        return $this->security->executeSecure(function() use ($file) {
            // Validate file
            $this->validateFile($file);
            
            // Process and store file
            $media = $this->media->store($file);
            
            return $media->toArray();
        }, ['action' => 'media.upload']);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecure(function() use ($id) {
            $media = $this->media->findOrFail($id);
            
            // Remove physical file
            Storage::delete($media->path);
            
            // Delete database record
            $this->media->delete($id);
            
            return true;
        }, ['action' => 'media.delete', 'media_id' => $id]);
    }

    protected function validateFile(array $file): void
    {
        if (!isset($file['size']) || $file['size'] > $this->maxFileSize) {
            throw new ValidationException('File size exceeds maximum allowed size');
        }

        if (!isset($file['type']) || !in_array($file['type'], $this->allowedTypes)) {
            throw new ValidationException('File type not allowed');
        }

        // Validate file contents
        $this->validateFileContents($file['tmp_name']);
    }

    protected function validateFileContents(string $path): void
    {
        // Check for malware/viruses
        if (!$this->scanFile($path)) {
            throw new SecurityException('File failed security scan');
        }

        // Validate file integrity
        if (!$this->verifyFileIntegrity($path)) {
            throw new SecurityException('File integrity check failed');
        }
    }

    protected function scanFile(string $path): bool
    {
        // Implement antivirus scanning
        return true;
    }

    protected function verifyFileIntegrity(string $path): bool
    {
        // Implement file integrity checking
        return true;
    }
}.create']);
    }

    public function update(int $id, array $data): array
    {
        return $this->security->executeSecure(function() use ($id, $data) {
            // Validate and update content
            $content = $this->content->update($id, $data);
            
            // Update media attachments
            if (isset($data['media'])) {
                $this->syncMedia($content, $data['media']);
            }

            // Clear relevant caches
            $this->clearContentCaches($id);
            
            return $content->toArray();
        }, ['action' => 'content