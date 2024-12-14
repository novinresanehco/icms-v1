<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Media\DTO\{MediaRequest, MediaResponse};
use App\Core\Exceptions\{MediaException, ValidationException};
use Illuminate\Support\Facades\{Storage, DB, Log};

class MediaManager implements MediaInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private MediaRepository $repository;
    private ImageProcessor $imageProcessor;
    private FileProcessor $fileProcessor;
    private CacheManager $cache;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        MediaRepository $repository,
        ImageProcessor $imageProcessor,
        FileProcessor $fileProcessor,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->imageProcessor = $imageProcessor;
        $this->fileProcessor = $fileProcessor;
        $this->cache = $cache;
    }

    public function upload(MediaRequest $request): MediaResponse
    {
        return DB::transaction(function() use ($request) {
            // Validate request and security
            $this->validateRequest($request);
            $context = $this->createSecurityContext($request);
            $this->security->validateCriticalOperation($context);

            // Process and store file
            $file = $request->getFile();
            $processedFile = $this->processFile($file);
            
            // Create media record
            $media = $this->repository->create([
                'type' => $processedFile->type,
                'path' => $processedFile->path,
                'mime_type' => $processedFile->mimeType,
                'size' => $processedFile->size,
                'metadata' => $processedFile->metadata,
                'checksum' => $processedFile->checksum
            ]);

            return new MediaResponse($media);
        });
    }

    public function process(int $id, array $operations = []): MediaResponse
    {
        return DB::transaction(function() use ($id, $operations) {
            $media = $this->repository->findOrFail($id);
            
            foreach ($operations as $operation) {
                $media = $this->processOperation($media, $operation);
            }
            
            $this->cache->invalidate($this->getCacheKey($id));
            
            return new MediaResponse($media);
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function() use ($id) {
            $media = $this->repository->findOrFail($id);
            
            // Delete file
            Storage::delete($media->path);
            
            // Delete thumbnails if exist
            if (!empty($media->metadata['thumbnails'])) {
                foreach ($media->metadata['thumbnails'] as $thumbnail) {
                    Storage::delete($thumbnail);
                }
            }
            
            // Delete record
            $this->repository->delete($id);
            
            // Clear cache
            $this->cache->invalidate($this->getCacheKey($id));
        });
    }

    public function get(int $id): MediaResponse
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            fn() => new MediaResponse(
                $this->repository->findOrFail($id)
            )
        );
    }

    protected function processFile($file): ProcessedFile
    {
        // Validate file
        $this->validateFile($file);
        
        // Calculate checksum
        $checksum = hash_file('sha256', $file->getPathname());
        
        // Check for duplicates
        if ($existing = $this->findDuplicate($checksum)) {
            return $existing;
        }
        
        // Process based on type
        $fileType = $this->determineFileType($file);
        
        if ($this->isImage($fileType)) {
            return $this->processImage($file);
        }
        
        return $this->processGenericFile($file);
    }

    protected function processImage($file): ProcessedFile
    {
        // Process image with security checks
        $processed = $this->imageProcessor->process($file, [
            'max_size' => config('media.image.max_size'),
            'allowed_types' => config('media.image.allowed_types'),
            'sanitize' => true
        ]);
        
        // Generate thumbnails
        $thumbnails = $this->generateThumbnails($processed->path);
        $processed->metadata['thumbnails'] = $thumbnails;
        
        return $processed;
    }

    protected function processGenericFile($file): ProcessedFile
    {
        return $this->fileProcessor->process($file, [
            'max_size' => config('media.file.max_size'),
            'allowed_types' => config('media.file.allowed_types'),
            'sanitize' => true
        ]);
    }

    protected function generateThumbnails(string $path): array
    {
        $thumbnails = [];
        $sizes = config('media.thumbnails.sizes', []);
        
        foreach ($sizes as $size => $dimensions) {
            $thumbnails[$size] = $this->imageProcessor->createThumbnail(
                $path,
                $dimensions['width'],
                $dimensions['height']
            );
        }
        
        return $thumbnails;
    }

    protected function validateFile($file): void
    {
        if (!$this->validator->validateFile(
            $file,
            config('media.validation.rules')
        )) {
            throw new ValidationException('Invalid file');
        }
    }

    protected function findDuplicate(string $checksum): ?ProcessedFile
    {
        $existing = $this->repository->findByChecksum($checksum);
        
        if ($existing) {
            return new ProcessedFile(
                $existing->path,
                $existing->type,
                $existing->mime_type,
                $existing->size,
                $existing->metadata,
                $checksum
            );
        }
        
        return null;
    }

    protected function processOperation($media, array $operation): Media
    {
        switch ($operation['type']) {
            case 'resize':
                return $this->resizeMedia($media, $operation);
            case 'optimize':
                return $this->optimizeMedia($media, $operation);
            case 'convert':
                return $this->convertMedia($media, $operation);
            default:
                throw new MediaException("Unknown operation: {$operation['type']}");
        }
    }

    protected function resizeMedia($media, array $params): Media
    {
        $processed = $this->imageProcessor->resize(
            $media->path,
            $params['width'],
            $params['height']
        );
        
        return $this->repository->update($media->id, [
            'path' => $processed->path,
            'size' => $processed->size,
            'metadata' => array_merge(
                $media->metadata,
                ['resized' => true]
            )
        ]);
    }

    protected function optimizeMedia($media, array $params): Media
    {
        $processed = $this->imageProcessor->optimize(
            $media->path,
            $params['quality'] ?? 85
        );
        
        return $this->repository->update($media->id, [
            'path' => $processed->path,
            'size' => $processed->size,
            'metadata' => array_merge(
                $media->metadata,
                ['optimized' => true]
            )
        ]);
    }

    protected function convertMedia($media, array $params): Media
    {
        $processed = $this->imageProcessor->convert(
            $media->path,
            $params['format']
        );
        
        return $this->repository->update($media->id, [
            'path' => $processed->path,
            'mime_type' => $processed->mimeType,
            'size' => $processed->size,
            'metadata' => array_merge(
                $media->metadata,
                ['converted' => true]
            )
        ]);
    }

    protected function determineFileType($file): string
    {
        return $file->getMimeType();
    }

    protected function isImage(string $mimeType): bool
    {
        return strpos($mimeType, 'image/') === 0;
    }

    protected function getCacheKey(int $id): string
    {
        return "media:{$id}";
    }

    protected function createSecurityContext(MediaRequest $request): SecurityContext
    {
        return new SecurityContext([
            'operation' => 'media.upload',
            'mime_type' => $request->getFile()->getMimeType(),
            'size' => $request->getFile()->getSize(),
            'ip' => request()->ip()
        ]);
    }
}
