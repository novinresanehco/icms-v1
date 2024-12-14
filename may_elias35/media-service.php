<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Storage, Cache, Log};
use Illuminate\Http\UploadedFile;
use App\Core\Security\SecurityManager;
use App\Core\Repository\MediaRepository;
use App\Core\Events\MediaEvent;
use App\Core\Exceptions\{
    MediaException,
    SecurityException,
    ValidationException
};
use Intervention\Image\ImageManager;

class MediaService
{
    protected MediaRepository $repository;
    protected SecurityManager $security;
    protected ImageManager $imageManager;
    protected array $config;
    protected string $disk;

    public function __construct(
        MediaRepository $repository,
        SecurityManager $security,
        ImageManager $imageManager
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->imageManager = $imageManager;
        $this->config = config('cms.media');
        $this->disk = config('cms.media.disk', 'public');
    }

    public function upload(UploadedFile $file, array $options = []): array
    {
        return $this->executeSecure('upload', function() use ($file, $options) {
            // Validate file
            $this->validateFile($file);
            
            // Generate secure filename
            $filename = $this->generateSecureFilename($file);
            
            // Process and store file
            $path = $this->processAndStore($file, $filename, $options);
            
            // Create media record
            $media = $this->repository->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $this->extractMetadata($file)
            ]);

            // Generate variants if needed
            if ($this->shouldGenerateVariants($file)) {
                $this->generateVariants($media);
            }

            event(new MediaEvent('uploaded', $media));

            return $this->formatMediaResponse($media);
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecure('delete', function() use ($id) {
            $media = $this->repository->find($id);
            
            if (!$media) {
                throw new MediaException("Media not found: {$id}");
            }

            // Delete file and variants
            $this->deleteFiles($media);
            
            // Delete record
            $result = $this->repository->delete($id);
            
            event(new MediaEvent('deleted', $media));
            
            return $result;
        });
    }

    public function get(int $id, array $options = []): array
    {
        return Cache::remember(
            $this->getCacheKey('media', $id, $options),
            $this->config['cache_ttl'] ?? 3600,
            function() use ($id, $options) {
                return $this->executeSecure('get', function() use ($id, $options) {
                    $media = $this->repository->find($id);
                    
                    if (!$media) {
                        throw new MediaException("Media not found: {$id}");
                    }

                    return $this->formatMediaResponse($media, $options);
                });
            }
        );
    }

    public function processMediaItems(array $items, array $options = []): array
    {
        return array_map(function($item) use ($options) {
            return $this->processMediaItem($item, $options);
        }, $items);
    }

    public function deleteForContent(int $contentId): void
    {
        $this->executeSecure('deleteForContent', function() use ($contentId) {
            $items = $this->repository->findByContent($contentId);
            
            foreach ($items as $item) {
                $this->delete($item->id);
            }
        });
    }

    protected function executeSecure(string $operation, callable $callback): mixed
    {
        $context = $this->createSecurityContext($operation);
        
        try {
            $this->security->validateOperation($context);
            
            $result = $callback();
            
            $this->security->verifyResult($result, $context);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw $e;
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), $this->config['allowed_types'])) {
            throw new ValidationException('File type not allowed');
        }

        if ($file->getSize() > $this->config['max_size']) {
            throw new ValidationException('File size exceeds limit');
        }
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        return sprintf(
            '%s.%s',
            hash('sha256', uniqid() . time()),
            $extension
        );
    }

    protected function processAndStore(UploadedFile $file, string $filename, array $options): string
    {
        // Process image if needed
        if ($this->isImage($file) && !empty($options['process'])) {
            $file = $this->processImage($file, $options['process']);
        }

        // Store file
        $path = Storage::disk($this->disk)->putFileAs(
            $this->getStoragePath(),
            $file,
            $filename
        );

        if (!$path) {
            throw new MediaException('Failed to store file');
        }

        return $path;
    }

    protected function processImage(UploadedFile $file, array $options): UploadedFile
    {
        $image = $this->imageManager->make($file);

        if (isset($options['resize'])) {
            $image->resize(
                $options['resize']['width'] ?? null,
                $options['resize']['height'] ?? null,
                function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                }
            );
        }

        if (isset($options['crop'])) {
            $image->crop(
                $options['crop']['width'],
                $options['crop']['height'],
                $options['crop']['x'] ?? 0,
                $options['crop']['y'] ?? 0
            );
        }

        // Save processed image
        $tempPath = tempnam(sys_get_temp_dir(), 'media_');
        $image->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $file->getClientOriginalName(),
            $file->getMimeType(),
            null,
            true
        );
    }

    protected function generateVariants(object $media): void
    {
        if (!$this->isImage($media)) {
            return;
        }

        foreach ($this->config['variants'] as $name => $options) {
            $this->generateVariant($media, $name, $options);
        }
    }

    protected function generateVariant(object $media, string $name, array $options): void
    {
        $path = Storage::disk($this->disk)->path($media->path);
        $image = $this->imageManager->make($path);

        // Apply transformations
        if (isset($options['resize'])) {
            $image->resize(
                $options['resize']['width'] ?? null,
                $options['resize']['height'] ?? null,
                function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                }
            );
        }

        // Save variant
        $variantPath = $this->getVariantPath($media->path, $name);
        Storage::disk($this->disk)->put(
            $variantPath,
            $image->encode()
        );

        // Update media record
        $this->repository->update($media->id, [
            'variants' => array_merge(
                $media->variants ?? [],
                [$name => $variantPath]
            )
        ]);
    }

    protected function deleteFiles(object $media): void
    {
        // Delete main file
        Storage::disk($this->disk)->delete($media->path);

        // Delete variants
        if (!empty($media->variants)) {
            foreach ($media->variants as $variant) {
                Storage::disk($this->disk)->delete($variant);
            }
        }
    }

    protected function formatMediaResponse(object $media, array $options = []): array
    {
        $response = [
            'id' => $media->id,
            'filename' => $media->filename,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'url' => $this->getMediaUrl($media->path),
            'metadata' => $media->metadata
        ];

        if (!empty($media->variants)) {
            $response['variants'] = array_map(
                fn($path) => $this->getMediaUrl($path),
                $media->variants
            );
        }

        return $response;
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];

        if ($this->isImage($file)) {
            [$width, $height] = getimagesize($file->getPathname());
            $metadata['dimensions'] = compact('width', 'height');
        }

        return $metadata;
    }

    protected function isImage($file): bool
    {
        $mimeType = $file instanceof UploadedFile 
            ? $file->getMimeType() 
            : $file->mime_type;
            
        return strpos($mimeType, 'image/') === 0;
    }

    protected function shouldGenerateVariants($file): bool
    {
        return $this->isImage($file) && !empty($this->config['variants']);
    }

    protected function getStoragePath(): string
    {
        return date('Y/m/d');
    }

    protected function getVariantPath(string $originalPath, string $variant): string
    {
        $info = pathinfo($originalPath);
        return sprintf(
            '%s/%s-%s.%s',
            $info['dirname'],
            $info['filename'],
            $variant,
            $info['extension']
        );
    }

    protected function getMediaUrl(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    protected function getCacheKey(string $type, int $id, array $options = []): string
    {
        return sprintf(
            'media:%s:%d:%s',
            $type,
            $id,
            md5(serialize($options))
        );
    }

    protected function createSecurityContext(string $operation): array
    {
        return [
            'operation' => $operation,
            'service' => self::class,
            'timestamp' => now(),
            'user_id' => auth()->id()
        ];
    }

    protected function handleException(\Exception $e, array $context): void
    {
        Log::error('Media operation failed', [
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
