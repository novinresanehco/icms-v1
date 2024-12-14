<?php

namespace App\Core\Media;

use App\Core\Security\{SecurityContext, CoreSecurityManager};
use App\Core\Cache\CachePerformanceManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB};

class MediaManager implements MediaManagementInterface
{
    private CoreSecurityManager $security;
    private CachePerformanceManager $cache;
    private ValidationService $validator;
    private StorageManager $storage;
    private MediaRepository $repository;
    private ImageProcessor $imageProcessor;
    private MetricsCollector $metrics;

    public function __construct(
        CoreSecurityManager $security,
        CachePerformanceManager $cache,
        ValidationService $validator,
        StorageManager $storage,
        MediaRepository $repository,
        ImageProcessor $imageProcessor,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->repository = $repository;
        $this->imageProcessor = $imageProcessor;
        $this->metrics = $metrics;
    }

    public function upload(UploadedFile $file, array $options, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            new MediaOperation('upload', ['file' => $file, 'options' => $options]),
            $context,
            function() use ($file, $options) {
                $this->validator->validateFile($file, $this->getAllowedTypes());
                $validated = $this->validator->validate($options, $this->getUploadRules());

                DB::beginTransaction();
                try {
                    $path = $this->storage->store($file, $validated['path'] ?? 'media');
                    $hash = hash_file('sha256', $file->getRealPath());
                    
                    $media = $this->repository->create([
                        'path' => $path,
                        'filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'hash' => $hash,
                        'meta' => $validated['meta'] ?? [],
                    ]);

                    if ($this->isImage($file)) {
                        $this->processImage($media, $file, $validated);
                    }

                    DB::commit();
                    $this->cache->invalidateTag('media');
                    
                    return $media;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->storage->delete($path);
                    throw $e;
                }
            }
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new MediaOperation('delete', ['id' => $id]),
            $context,
            function() use ($id) {
                $media = $this->repository->findOrFail($id);
                
                DB::beginTransaction();
                try {
                    $this->storage->delete($media->path);
                    $this->deleteVariants($media);
                    $this->repository->delete($id);
                    
                    DB::commit();
                    $this->cache->invalidateTag('media');
                    
                    return true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    public function get(int $id, SecurityContext $context): ?Media
    {
        return $this->cache->remember(
            "media.{$id}",
            function() use ($id, $context) {
                return $this->security->executeCriticalOperation(
                    new MediaOperation('read', ['id' => $id]),
                    $context,
                    fn() => $this->repository->findWithVariants($id)
                );
            }
        );
    }

    public function getUrl(Media $media, ?string $variant = null): string
    {
        if ($variant && isset($media->variants[$variant])) {
            return $this->storage->url($media->variants[$variant]['path']);
        }
        return $this->storage->url($media->path);
    }

    public function optimize(int $id, array $options, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(
            new MediaOperation('optimize', ['id' => $id, 'options' => $options]),
            $context,
            function() use ($id, $options) {
                $media = $this->repository->findOrFail($id);
                
                if (!$this->isImage($media)) {
                    throw new MediaException('Only images can be optimized');
                }

                $validated = $this->validator->validate($options, $this->getOptimizationRules());
                
                DB::beginTransaction();
                try {
                    $optimized = $this->imageProcessor->optimize(
                        $this->storage->path($media->path),
                        $validated
                    );

                    $this->storage->put(
                        $media->path,
                        $optimized->getEncodedContent()
                    );

                    $media->size = $optimized->getSize();
                    $media->save();

                    DB::commit();
                    $this->cache->invalidateTag('media');
                    
                    return $media;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    private function processImage(Media $media, UploadedFile $file, array $options): void
    {
        $variants = $options['variants'] ?? $this->getDefaultVariants();
        
        foreach ($variants as $name => $config) {
            $processed = $this->imageProcessor->process(
                $file->getRealPath(),
                $config
            );

            $variantPath = $this->storage->storeProcessed(
                $processed,
                $name,
                $media->path
            );

            $this->repository->addVariant($media->id, [
                'name' => $name,
                'path' => $variantPath,
                'width' => $processed->getWidth(),
                'height' => $processed->getHeight(),
                'size' => $processed->getSize()
            ]);
        }
    }

    private function deleteVariants(Media $media): void
    {
        foreach ($media->variants as $variant) {
            $this->storage->delete($variant['path']);
        }
        $this->repository->deleteVariants($media->id);
    }

    private function isImage($file): bool
    {
        return str_starts_with(
            $file instanceof UploadedFile ? 
            $file->getMimeType() : 
            $file->mime_type,
            'image/'
        );
    }

    private function getAllowedTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'text/csv'
        ];
    }

    private function getUploadRules(): array
    {
        return [
            'path' => 'string',
            'meta' => 'array',
            'variants' => 'array',
            'variants.*.width' => 'integer|min:1',
            'variants.*.height' => 'integer|min:1',
            'variants.*.quality' => 'integer|min:1|max:100'
        ];
    }

    private function getOptimizationRules(): array
    {
        return [
            'quality' => 'integer|min:1|max:100',
            'strip' => 'boolean',
            'convert' => 'string|in:webp,jpeg,png'
        ];
    }

    private function getDefaultVariants(): array
    {
        return [
            'thumbnail' => [
                'width' => 150,
                'height' => 150,
                'quality' => 80
            ],
            'medium' => [
                'width' => 800,
                'height' => 600,
                'quality' => 85
            ]
        ];
    }
}
