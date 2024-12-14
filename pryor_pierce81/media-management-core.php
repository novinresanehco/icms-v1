namespace App\Core\Media;

class MediaManager implements MediaManagementInterface
{
    private SecurityManager $security;
    private StorageService $storage;
    private ImageProcessor $processor;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        ImageProcessor $processor,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->processor = $processor;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function upload(UploadedFile $file): Media
    {
        return $this->security->executeCriticalOperation(
            new MediaUploadOperation(
                $file,
                $this->storage,
                $this->validator,
                $this->metrics
            ),
            SecurityContext::fromRequest()
        );
    }

    public function process(Media $media): void
    {
        $this->security->executeCriticalOperation(
            new MediaProcessOperation(
                $media,
                $this->processor,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function optimize(Media $media): void
    {
        $this->security->executeCriticalOperation(
            new MediaOptimizeOperation(
                $media,
                $this->processor,
                $this->metrics
            ),
            SecurityContext::fromRequest()
        );
    }

    public function attachToContent(int $mediaId, int $contentId): void
    {
        $this->security->executeCriticalOperation(
            new MediaAttachOperation(
                $mediaId,
                $contentId,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function generateThumbnails(Media $media): array
    {
        return $this->security->executeCriticalOperation(
            new ThumbnailGenerationOperation(
                $media,
                $this->processor,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    private function validateMedia(UploadedFile $file): void
    {
        $rules = [
            'size' => 'max:' . config('media.max_size'),
            'mimes' => config('media.allowed_mimes'),
            'dimensions' => config('media.dimensions')
        ];

        if (!$this->validator->validate($file, $rules)) {
            throw new MediaValidationException('Invalid media file');
        }
    }

    private function processImage(Media $media): void
    {
        $operations = [
            'optimize' => [
                'quality' => 85,
                'strip' => true
            ],
            'resize' => [
                'width' => 1920,
                'height' => 1080,
                'aspect' => true
            ],
            'convert' => [
                'format' => 'webp'
            ]
        ];

        foreach ($operations as $operation => $params) {
            $this->processor->$operation($media->path, $params);
        }
    }

    private function generateCacheKey(Media $media, string $operation): string
    {
        return sprintf(
            'media.%s.%s.%s',
            $media->id,
            $operation,
            $media->updated_at->timestamp
        );
    }

    private function clearMediaCache(Media $media): void
    {
        $this->cache->tags(['media', "media.{$media->id}"])->flush();
    }

    public function delete(int $mediaId): bool
    {
        return $this->security->executeCriticalOperation(
            new MediaDeleteOperation(
                $mediaId,
                $this->storage,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function getUrl(Media $media, array $params = []): string
    {
        $cacheKey = $this->generateCacheKey($media, 'url');

        return $this->cache->remember($cacheKey, 3600, function () use ($media, $params) {
            return $this->storage->getUrl($media->path, $params);
        });
    }

    public function getMetadata(Media $media): array
    {
        $cacheKey = $this->generateCacheKey($media, 'metadata');

        return $this->cache->remember($cacheKey, 3600, function () use ($media) {
            return $this->storage->getMetadata($media->path);
        });
    }
}
