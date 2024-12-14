<?php

namespace App\Core\Media;

class MediaManager implements MediaManagerInterface 
{
    private SecurityManager $security;
    private StorageManager $storage;
    private ValidationService $validator;
    private CacheManager $cache;
    private ImageProcessor $imageProcessor;
    private Repository $repository;

    public function store(UploadedFile $file, array $options = []): MediaResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'media.store',
                'file' => $file,
                'options' => $options
            ]);

            // Validate file
            $this->validator->validateFile($file, [
                'max_size' => config('cms.media.max_size'),
                'allowed_types' => config('cms.media.allowed_types'),
                'malware_scan' => true
            ]);

            // Generate secure filename
            $filename = $this->generateSecureFilename($file);
            
            // Process and optimize image if applicable
            if ($this->isImage($file)) {
                $file = $this->imageProcessor->process($file, [
                    'optimize' => true,
                    'max_dimensions' => config('cms.media.max_dimensions'),
                    'strip_metadata' => true
                ]);
            }

            // Store file with encryption
            $path = $this->storage->store(
                $file,
                $filename,
                ['encrypt' => true]
            );

            // Create media record
            $media = $this->repository->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'checksum' => $this->generateChecksum($file),
                'meta' => $options['meta'] ?? []
            ]);

            // Cache management
            $this->cache->tags(['media'])->put(
                $this->getCacheKey($media->id),
                $media,
                config('cms.cache.ttl')
            );

            DB::commit();
            
            return new MediaResult($media);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->storage->deleteIfExists($filename ?? null);
            throw $e;
        }
    }

    public function get(int $id): ?MediaResult 
    {
        return $this->cache->tags(['media'])->remember(
            $this->getCacheKey($id),
            config('cms.cache.ttl'),
            function() use ($id) {
                $media = $this->repository->find($id);
                return $media ? new MediaResult($media) : null;
            }
        );
    }

    public function delete(int $id): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'media.delete',
                'media_id' => $id
            ]);

            $media = $this->repository->findOrFail($id);
            
            // Delete physical file
            $this->storage->delete($media->path);
            
            // Delete db record
            $this->repository->delete($id);
            
            // Clear cache
            $this->cache->tags(['media'])->forget($this->getCacheKey($id));
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function processContentMedia(Content $content, array $mediaIds): void 
    {
        foreach ($mediaIds as $mediaId) {
            $media = $this->repository->findOrFail($mediaId);
            
            // Verify media ownership and permissions
            $this->security->validateCriticalOperation([
                'action' => 'media.attach',
                'content_id' => $content->id,
                'media_id' => $mediaId
            ]);

            // Create content-media relationship
            $content->media()->attach($mediaId);
        }
    }

    public function updateContentMedia(Content $content, array $mediaIds): void 
    {
        // Verify all media items first
        foreach ($mediaIds as $mediaId) {
            $this->repository->findOrFail($mediaId);
        }

        $this->security->validateCriticalOperation([
            'action' => 'media.sync',
            'content_id' => $content->id,
            'media_ids' => $mediaIds
        ]);

        // Sync media relationships
        $content->media()->sync($mediaIds);
    }

    public function deleteContentMedia(Content $content): void 
    {
        $mediaIds = $content->media()->pluck('id')->toArray();
        
        foreach ($mediaIds as $mediaId) {
            $this->delete($mediaId);
        }
    }

    private function generateSecureFilename(UploadedFile $file): string 
    {
        return sprintf(
            '%s_%s.%s',
            Str::random(32),
            time(),
            $file->getClientOriginalExtension()
        );
    }

    private function generateChecksum(UploadedFile $file): string 
    {
        return hash_file('sha256', $file->getPathname());
    }

    private function isImage(UploadedFile $file): bool 
    {
        return Str::startsWith($file->getMimeType(), 'image/');
    }

    private function getCacheKey(int $id): string 
    {
        return "media.{$id}";
    }
}
