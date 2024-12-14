<?php

namespace App\Core\Media;

class MediaManager implements MediaInterface
{
    private FileSystem $storage;
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private ImageProcessor $processor;
    private MetricsCollector $metrics;

    public function __construct(
        FileSystem $storage,
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        ImageProcessor $processor,
        MetricsCollector $metrics
    ) {
        $this->storage = $storage;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->metrics = $metrics;
    }

    public function upload(UploadedFile $file, array $options = []): MediaFile
    {
        return $this->security->executeCriticalOperation(new UploadOperation(
            $file,
            $options,
            $this->storage,
            $this->validator,
            $this->processor,
            $this->metrics
        ));
    }

    public function get(string $id): ?MediaFile
    {
        return $this->cache->remember(
            "media.{$id}",
            fn() => $this->storage->find($id)
        );
    }

    public function delete(string $id): bool
    {
        $result = $this->security->executeCriticalOperation(new DeleteMediaOperation(
            $id,
            $this->storage,
            $this->metrics
        ));

        if ($result) {
            $this->cache->forget("media.{$id}");
        }

        return $result;
    }

    public function process(string $id, array $operations): MediaFile
    {
        return $this->security->executeCriticalOperation(new ProcessMediaOperation(
            $id,
            $operations,
            $this->storage,
            $this->processor,
            $this->metrics
        ));
    }
}

final class UploadOperation implements CriticalOperation
{
    private UploadedFile $file;
    private array $options;
    private FileSystem $storage;
    private ValidationService $validator;
    private ImageProcessor $processor;
    private MetricsCollector $metrics;

    public function __construct(
        UploadedFile $file,
        array $options,
        FileSystem $storage,
        ValidationService $validator,
        ImageProcessor $processor,
        MetricsCollector $metrics
    ) {
        $this->file = $file;
        $this->options = $options;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->metrics = $metrics;
    }

    public function execute(): MediaFile
    {
        $startTime = microtime(true);

        $this->validateFile($this->file);
        $this->sanitizeFile($this->file);
        
        $id = $this->generateSecureId();
        $path = $this->determineStoragePath($id);
        
        $metadata = [
            'mime_type' => $this->file->getMimeType(),
            'size' => $this->file->getSize(),
            'original_name' => $this->file->getClientOriginalName(),
            'hash' => $this->calculateFileHash($this->file)
        ];

        if ($this->isImage($this->file)) {
            $metadata = array_merge($metadata, $this->processImage($this->file));
        }

        $file = $this->storage->store($path, $this->file, $metadata);
        
        $this->metrics->recordUpload([
            'id' => $id,
            'size' => $metadata['size'],
            'type' => $metadata['mime_type'],
            'duration' => microtime(true) - $startTime
        ]);

        return $file;
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new InvalidUploadException($file->getErrorMessage());
        }

        $this->validator->validateUpload($file, [
            'max_size' => config('media.max_size'),
            'allowed_types' => config('media.allowed_types'),
            'disallowed_extensions' => config('media.disallowed_extensions')
        ]);
    }

    private function sanitizeFile(UploadedFile $file): void
    {
        $file->sanitize([
            'strip_tags' => true,
            'remove_scripts' => true,
            'scan_malware' => true
        ]);
    }

    private function generateSecureId(): string
    {
        return hash('sha256', uniqid('', true));
    }

    private function determineStoragePath(string $id): string
    {
        return date('Y/m/d/') . substr($id, 0, 2) . '/' . substr($id, 2);
    }

    private function calculateFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    private function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }

    private function processImage(UploadedFile $file): array
    {
        $image = $this->processor->load($file);
        
        $metadata = [
            'dimensions' => $image->getDimensions(),
            'format' => $image->getFormat(),
            'color_space' => $image->getColorSpace()
        ];

        if ($this->options['optimize'] ?? true) {
            $image->optimize([
                'quality' => 85,
                'strip_metadata' => true,
                'progressive' => true
            ]);
        }

        if ($this->options['generate_thumbnails'] ?? true) {
            $this->generateThumbnails($image);
        }

        return $metadata;
    }

    private function generateThumbnails(Image $image): void
    {
        foreach (config('media.thumbnail_sizes') as $size => $dimensions) {
            $thumbnail = $image->resize($dimensions);
            $this->storage->storeThumbnail($image->getId(), $size, $thumbnail);
        }
    }

    public function getRequiredPermission(): string
    {
        return 'media.upload';
    }

    public function getRateLimitKey(): string
    {
        return 'media.upload.' . request()->ip();
    }

    public function requiresRecovery(): bool
    {
        return true;
    }
}

interface MediaInterface
{
    public function upload(UploadedFile $file, array $options = []): MediaFile;
    public function get(string $id): ?MediaFile;
    public function delete(string $id): bool;
    public function process(string $id, array $operations): MediaFile;
}
