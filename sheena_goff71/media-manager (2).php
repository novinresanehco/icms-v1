<?php

namespace App\Modules\Media;

use Illuminate\Support\Facades\{DB, Storage, File};
use App\Core\Service\BaseService;
use App\Core\Events\MediaEvent;
use App\Core\Support\Result;
use App\Core\Exceptions\{MediaException, ValidationException};
use App\Models\Media;
use Intervention\Image\Facades\Image;

class MediaManager extends BaseService
{
    protected array $validationRules = [
        'upload' => [
            'file' => 'required|file|max:10240',
            'mime_types' => 'allowed_mime_types',
            'type' => 'required|in:image,document,video',
            'folder' => 'required|string',
            'visibility' => 'required|in:public,private'
        ],
        'metadata' => [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'alt_text' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string'
        ]
    ];

    protected array $allowedMimeTypes = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'video' => ['video/mp4', 'video/webm']
    ];

    protected array $imageProcessingConfig = [
        'thumbnails' => [
            'small' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 300, 'height' => 300],
            'large' => ['width' => 600, 'height' => 600]
        ],
        'quality' => 80,
        'format' => 'jpg'
    ];

    public function upload(array $data): Result
    {
        return $this->executeOperation('upload', $data);
    }

    public function update(int $id, array $data): Result
    {
        $data['id'] = $id;
        return $this->executeOperation('update', $data);
    }

    public function delete(int $id): Result
    {
        return $this->executeOperation('delete', ['id' => $id]);
    }

    protected function processOperation(string $operation, array $data, array $context): mixed
    {
        return match($operation) {
            'upload' => $this->processUpload($data),
            'update' => $this->processUpdate($data),
            'delete' => $this->processDelete($data),
            default => throw new MediaException("Invalid operation: {$operation}")
        };
    }

    protected function processUpload(array $data): Media
    {
        $file = $data['file'];
        $type = $data['type'];
        $folder = $data['folder'];
        $visibility = $data['visibility'];

        // Validate mime type
        if (!$this->isValidMimeType($file, $type)) {
            throw new ValidationException('Invalid file type');
        }

        // Generate secure filename
        $filename = $this->generateSecureFilename($file);
        $path = "{$folder}/{$filename}";

        // Store file
        $storagePath = Storage::disk('media')->putFileAs(
            $folder,
            $file,
            $filename,
            $visibility
        );

        if (!$storagePath) {
            throw new MediaException('File storage failed');
        }

        // Process image if applicable
        $metadata = [];
        if ($type === 'image') {
            $metadata = $this->processImage($file, $folder, $filename);
        }

        // Create media record
        $media = $this->repository->create([
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'type' => $type,
            'metadata' => array_merge($metadata, $data['metadata'] ?? []),
            'visibility' => $visibility
        ]);

        // Generate file hash
        $this->generateFileHash($media);

        // Fire events
        $this->events->dispatch(new MediaEvent('uploaded', $media));

        return $media;
    }

    protected function processUpdate(array $data): Media
    {
        $media = $this->repository->findOrFail($data['id']);
        
        // Update metadata
        $metadata = array_merge($media->metadata, $data['metadata'] ?? []);
        
        $updated = $this->repository->update($media, [
            'metadata' => $metadata
        ]);

        // Fire events
        $this->events->dispatch(new MediaEvent('updated', $updated));

        return $updated;
    }

    protected function processDelete(array $data): bool
    {
        $media = $this->repository->findOrFail($data['id']);

        // Delete physical files
        $this->deleteMediaFiles($media);

        // Delete record
        $deleted = $this->repository->delete($media);

        // Fire events
        $this->events->dispatch(new MediaEvent('deleted', $media));

        return $deleted;
    }

    protected function processImage($file, string $folder, string $filename): array
    {
        $metadata = [
            'dimensions' => $this->getImageDimensions($file),
            'thumbnails' => []
        ];

        // Generate thumbnails
        foreach ($this->imageProcessingConfig['thumbnails'] as $size => $dimensions) {
            $thumbnailPath = $this->generateThumbnail(
                $file,
                $folder,
                $filename,
                $dimensions,
                $size
            );

            if ($thumbnailPath) {
                $metadata['thumbnails'][$size] = $thumbnailPath;
            }
        }

        return $metadata;
    }

    protected function generateThumbnail($file, string $folder, string $filename, array $dimensions, string $size): ?string
    {
        try {
            $image = Image::make($file);
            $image->fit($dimensions['width'], $dimensions['height']);

            $thumbnailFilename = "thumb_{$size}_{$filename}";
            $thumbnailPath = "{$folder}/thumbnails/{$thumbnailFilename}";

            Storage::disk('media')->put(
                $thumbnailPath,
                $image->encode($this->imageProcessingConfig['format'], $this->imageProcessingConfig['quality'])
            );

            return $thumbnailPath;

        } catch (Exception $e) {
            Log::error('Thumbnail generation failed', [
                'file' => $filename,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function deleteMediaFiles(Media $media): void
    {
        // Delete main file
        Storage::disk('media')->delete($media->path);

        // Delete thumbnails if they exist
        if ($media->type === 'image' && isset($media->metadata['thumbnails'])) {
            foreach ($media->metadata['thumbnails'] as $thumbnailPath) {
                Storage::disk('media')->delete($thumbnailPath);
            }
        }
    }

    protected function generateSecureFilename($file): string
    {
        $extension = $file->getClientOriginalExtension();
        return sprintf(
            '%s_%s.%s',
            uniqid('media_', true),
            hash('xxh3', $file->getRealPath()),
            $extension
        );
    }

    protected function generateFileHash(Media $media): void
    {
        $path = Storage::disk('media')->path($media->path);
        $hash = hash_file('sha256', $path);
        
        $this->repository->update($media, ['hash' => $hash]);
    }

    protected function isValidMimeType($file, string $type): bool
    {
        return in_array(
            $file->getMimeType(),
            $this->allowedMimeTypes[$type]
        );
    }

    protected function getImageDimensions($file): array
    {
        $image = Image::make($file);
        return [
            'width' => $image->width(),
            'height' => $image->height()
        ];
    }

    protected function getValidationRules(string $operation): array
    {
        return array_merge(
            $this->validationRules[$operation] ?? [],
            $this->validationRules['metadata'] ?? []
        );
    }

    protected function getRequiredPermissions(string $operation): array
    {
        return ["media.{$operation}"];
    }

    protected function isValidResult(string $operation, $result): bool
    {
        return match($operation) {
            'upload', 'update' => $result instanceof Media,
            'delete' => is_bool($result),
            default => false
        };
    }
}
