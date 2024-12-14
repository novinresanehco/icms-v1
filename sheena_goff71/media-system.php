<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{Storage, DB};
use App\Core\Security\{SecurityManager, ValidationService};
use Illuminate\Http\UploadedFile;

class MediaManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $allowedTypes = ['image', 'document', 'video'];
    private array $secureStorageConfig;

    public function storeMedia(UploadedFile $file, array $metadata = []): Media
    {
        return DB::transaction(function() use ($file, $metadata) {
            $this->security->validateAccess('media', 'create');
            $this->validateFile($file);
            
            $hash = $this->generateFileHash($file);
            $sanitizedMetadata = $this->sanitizeMetadata($metadata);
            
            if ($existing = $this->findDuplicate($hash)) {
                return $this->handleDuplicate($existing, $sanitizedMetadata);
            }
            
            $path = $this->storeSecurely($file);
            $optimized = $this->optimizeMedia($file, $path);
            
            $media = Media::create([
                'path' => $path,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'metadata' => $sanitizedMetadata,
                'optimized_path' => $optimized['path'] ?? null,
                'created_by' => auth()->id()
            ]);
            
            $this->generateThumbnails($media);
            $this->audit->logMediaUpload($media);
            
            return $media;
        });
    }

    public function processMedia(int $id, array $operations): Media
    {
        return DB::transaction(function() use ($id, $operations) {
            $media = $this->findOrFail($id);
            $this->security->validateAccess('media', 'process');
            
            $this->validateOperations($operations);
            $processed = $this->applyOperations($media, $operations);
            
            $media->update([
                'processed_path' => $processed['path'],
                'processed_at' => now(),
                'process_metadata' => $processed['metadata']
            ]);
            
            $this->audit->logMediaProcessing($media, $operations);
            
            return $media;
        });
    }

    public function getMedia(int $id, array $options = []): Media
    {
        $media = $this->findOrFail($id);
        $this->security->validateAccess('media', 'read');
        
        if (!empty($options['transform'])) {
            return $this->transformMedia($media, $options['transform']);
        }
        
        return $media;
    }

    public function deleteMedia(int $id): void
    {
        DB::transaction(function() use ($id) {
            $media = $this->findOrFail($id);
            $this->security->validateAccess('media', 'delete');
            
            $this->deleteFiles($media);
            $media->delete();
            
            $this->audit->logMediaDeletion($media);
        });
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }
        
        if (!$this->validator->validateMimeType($file->getMimeType(), $this->allowedTypes)) {
            throw new MediaException('Invalid file type');
        }
        
        if ($file->getSize() > $this->config->getMaxFileSize()) {
            throw new MediaException('File size exceeds limit');
        }
    }

    private function storeSecurely(UploadedFile $file): string
    {
        $name = $this->generateSecureFilename($file);
        $path = date('Y/m/d') . '/' . $name;
        
        Storage::disk('secure')->put(
            $path,
            file_get_contents($file->getRealPath()),
            $this->secureStorageConfig
        );
        
        return $path;
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        return hash('sha256', uniqid() . $file->getClientOriginalName()) 
            . '.' . $file->getClientOriginalExtension();
    }

    private function optimizeMedia(UploadedFile $file, string $path): array
    {
        if (!$this->canOptimize($file)) {
            return [];
        }
        
        $optimizer = $this->getOptimizer($file->getMimeType());
        return $optimizer->optimize($path);
    }

    private function generateThumbnails(Media $media): void
    {
        if (!$this->canGenerateThumbnail($media)) {
            return;
        }
        
        foreach ($this->config->getThumbnailSizes() as $size) {
            $path = $this->thumbnailGenerator->generate($media, $size);
            
            MediaThumbnail::create([
                'media_id' => $media->id,
                'path' => $path,
                'size' => $size,
                'created_at' => now()
            ]);
        }
    }

    private function findDuplicate(string $hash): ?Media
    {
        return Media::where('hash', $hash)
            ->where('deleted_at', null)
            ->first();
    }

    private function handleDuplicate(Media $media, array $metadata): Media
    {
        $media->update([
            'duplicate_count' => $media->duplicate_count + 1,
            'last_duplicate_at' => now()
        ]);
        
        $this->audit->logDuplicateUpload($media);
        
        return $media;
    }

    private function generateFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    private function sanitizeMetadata(array $metadata): array
    {
        return array_intersect_key(
            $metadata,
            array_flip($this->config->getAllowedMetadataFields())
        );
    }

    private function deleteFiles(Media $media): void
    {
        Storage::disk('secure')->delete([
            $media->path,
            $media->optimized_path,
            $media->processed_path
        ]);
        
        foreach ($media->thumbnails as $thumbnail) {
            Storage::disk('secure')->delete($thumbnail->path);
        }
    }

    private function findOrFail(int $id): Media
    {
        $media = Media::find($id);
        
        if (!$media) {
            throw new MediaNotFoundException("Media with ID {$id} not found");
        }
        
        return $media;
    }
}
