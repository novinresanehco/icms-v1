<?php

namespace App\Core\Media;

use App\Core\Security\CoreSecurityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class MediaManager implements MediaManagementInterface 
{
    private CoreSecurityService $security;
    private MediaRepository $repository;
    private ImageManager $imageManager;
    private CacheManager $cache;
    private StorageService $storage;

    public function __construct(
        CoreSecurityService $security,
        MediaRepository $repository,
        ImageManager $imageManager,
        CacheManager $cache,
        StorageService $storage
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->imageManager = $imageManager;
        $this->cache = $cache;
        $this->storage = $storage;
    }

    public function upload(UploadedFile $file, array $options, Context $context): Media
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeUpload($file, $options),
            ['action' => 'media.upload', 'context' => $context]
        );
    }

    public function process(int $id, array $operations, Context $context): Media
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeProcess($id, $operations),
            ['action' => 'media.process', 'id' => $id, 'context' => $context]
        );
    }

    public function delete(int $id, Context $context): bool
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeDelete($id),
            ['action' => 'media.delete', 'id' => $id, 'context' => $context]
        );
    }

    private function executeUpload(UploadedFile $file, array $options): Media
    {
        $this->validateUpload($file, $options);
        
        $path = $this->storage->store($file, $options['directory'] ?? 'media');
        
        $media = $this->repository->create([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'meta' => $this->extractMetadata($file)
        ]);

        if ($this->isImage($file)) {
            $this->generateImageVariants($media);
        }

        return $media;
    }

    private function executeProcess(int $id, array $operations): Media
    {
        $media = $this->repository->find($id);
        if (!$media) {
            throw new MediaNotFoundException("Media not found: $id");
        }

        foreach ($operations as $operation) {
            $media = $this->processOperation($media, $operation);
        }

        return $media;
    }

    private function executeDelete(int $id): bool
    {
        $media = $this->repository->find($id);
        if (!$media) {
            return false;
        }

        $this->storage->delete($media->path);
        $this->deleteVariants($media);
        $this->cache->invalidatePattern("media:*:$id");
        
        return $this->repository->delete($id);
    }

    private function validateUpload(UploadedFile $file, array $options): void
    {
        $maxSize = config('media.max_upload_size', 10 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            throw new MediaValidationException('File exceeds maximum size limit');
        }

        $allowedTypes = config('media.allowed_types', []);
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw new MediaValidationException('File type not allowed');
        }

        if (!$file->isValid()) {
            throw new MediaValidationException('Invalid upload');
        }
    }

    private function extractMetadata(UploadedFile $file): array
    {
        $meta = [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];

        if ($this->isImage($file)) {
            [$width, $height] = getimagesize($file->getRealPath());
            $meta['dimensions'] = compact('width', 'height');
        }

        return $meta;
    }

    private function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }

    private function generateImageVariants(Media $media): void
    {
        $variants = config('media.image_variants', []);
        
        foreach ($variants as $name => $config) {
            $this->generateVariant($media, $name, $config);
        }
    }

    private function generateVariant(Media $media, string $name, array $config): void
    {
        $image = $this->imageManager->make($this->storage->path($media->path));

        if (isset($config['width'], $config['height'])) {
            $image->fit($config['width'], $config['height']);
        } elseif (isset($config['width'])) {
            $image->widen($config['width'], function ($constraint) {
                $constraint->upsize();
            });
        } elseif (isset($config['height'])) {
            $image->heighten($config['height'], function ($constraint) {
                $constraint->upsize();
            });
        }

        $quality = $config['quality'] ?? 90;
        $variantPath = $this->storage->generateVariantPath($media->path, $name);
        
        $image->save($this->storage->path($variantPath), $quality);

        $this->repository->addVariant($media->id, [
            'name' => $name,
            'path' => $variantPath,
            'meta' => [
                'width' => $image->width(),
                'height' => $image->height(),
                'quality' => $quality
            ]
        ]);
    }

    private function processOperation(Media $media, array $operation): Media
    {
        return match($operation['type']) {
            'resize' => $this->resizeImage($media, $operation),
            'crop' => $this->cropImage($media, $operation),
            'optimize' => $this->optimizeImage($media, $operation),
            default => throw new InvalidOperationException("Unknown operation: {$operation['type']}")
        };
    }

    private function resizeImage(Media $media, array $operation): Media
    {
        $image = $this->imageManager->make($this->storage->path($media->path));
        
        if (isset($operation['width'], $operation['height'])) {
            $image->resize($operation['width'], $operation['height']);
        } elseif (isset($operation['width'])) {
            $image->widen($operation['width']);
        } elseif (isset($operation['height'])) {
            $image->heighten($operation['height']);
        }

        $image->save();
        
        return $this->repository->update($media->id, [
            'meta' => array_merge($media->meta, [
                'dimensions' => [
                    'width' => $image->width(),
                    'height' => $image->height()
                ]
            ])
        ]);
    }

    private function cropImage(Media $media, array $operation): Media
    {
        $image = $this->imageManager->make($this->storage->path($media->path));
        
        $image->crop(
            $operation['width'],
            $operation['height'],
            $operation['x'] ?? 0,
            $operation['y'] ?? 0
        );

        $image->save();
        
        return $this->repository->update($media->id, [
            'meta' => array_merge($media->meta, [
                'dimensions' => [
                    'width' => $image->width(),
                    'height' => $image->height()
                ]
            ])
        ]);
    }

    private function optimizeImage(Media $media, array $operation): Media
    {
        $quality = $operation['quality'] ?? 85;
        $image = $this->imageManager->make($this->storage->path($media->path));
        $image->save(null, $quality);
        
        return $this->repository->update($media->id, [
            'meta' => array_merge($media->meta, [
                'quality' => $quality
            ])
        ]);
    }

    private function deleteVariants(Media $media): void
    {
        foreach ($media->variants as $variant) {
            $this->storage->delete($variant->path);
        }
    }
}

class MediaRepository
{
    public function find(int $id): ?Media
    {
        return Media::with('variants')->find($id);
    }

    public function create(array $data): Media
    {
        return Media::create($data);
    }

    public function update(int $id, array $data): Media
    {
        $media = $this->find($id);
        $media->update($data);
        return $media->fresh();
    }

    public function delete(int $id): bool
    {
        return Media::destroy($id) > 0;
    }

    public function addVariant(int $mediaId, array $data): MediaVariant
    {
        return MediaVariant::create(array_merge(
            $data,
            ['media_id' => $mediaId]
        ));
    }
}

class StorageService
{
    private string $disk;

    public function __construct(string $disk = 'media')
    {
        $this->disk = $disk;
    }

    public function store(UploadedFile $file, string $directory): string
    {
        return Storage::disk($this->disk)->putFile($directory, $file);
    }

    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    public function path(string $path): string
    {
        return Storage::disk($this->disk)->path($path);
    }

    public function generateVariantPath(string $originalPath, string $variant): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_' . $variant . '.' . 
               $pathInfo['extension'];
    }
}

class MediaValidationException extends \Exception {}
class MediaNotFoundException extends \Exception {}
class InvalidOperationException extends \Exception {}
