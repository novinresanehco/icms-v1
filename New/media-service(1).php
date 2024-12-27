<?php

namespace App\Core\Media;

class MediaManager implements MediaManagerInterface
{
    private MediaRepository $repository;
    private StorageService $storage;
    private SecurityService $security;
    private ValidationService $validator;
    private ImageProcessor $processor;
    private EventManager $events;

    public function __construct(
        MediaRepository $repository,
        StorageService $storage,
        SecurityService $security,
        ValidationService $validator,
        ImageProcessor $processor,
        EventManager $events
    ) {
        $this->repository = $repository;
        $this->storage = $storage;
        $this->security = $security;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->events = $events;
    }

    public function store(UploadedFile $file, array $metadata = []): Media
    {
        return DB::transaction(function() use ($file, $metadata) {
            // Validate file
            $this->validateFile($file);
            
            // Store file securely
            $path = $this->storage->store($file, $this->generateSecurePath());
            
            // Process image if needed
            if ($this->isImage($file)) {
                $this->processImage($path);
            }
            
            // Create media record
            $media = $this->repository->create([
                'path' => $path,
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $this->security->encryptData($metadata)
            ]);
            
            // Generate thumbnails
            $this->generateThumbnails($media);
            
            // Dispatch event
            $this->events->dispatch(new MediaStored($media));
            
            return $media;
        });
    }

    public function process(Media $media): void
    {
        if ($this->isImage($media)) {
            $this->processImage($media->path);
            $this->generateThumbnails($media);
        }
        
        $this->optimizer->optimize($media->path);
        $media->update(['processed' => true]);
    }

    public function delete(Media $media): void
    {
        DB::transaction(function() use ($media) {
            // Delete file
            $this->storage->delete($media->path);
            
            // Delete thumbnails
            $this->deleteThumbnails($media);
            
            // Delete record
            $this->repository->delete($media->id);
            
            // Dispatch event
            $this->events->dispatch(new MediaDeleted($media));
        });
    }

    protected function validateFile(UploadedFile $file): void
    {
        $this->validator->validate(['file' => $file], [
            'file' => 'required|file|mimes:jpeg,png,pdf|max:10240'
        ]);
    }

    protected function generateSecurePath(): string
    {
        return sprintf(
            'media/%s/%s',
            date('Y/m'),
            Str::random(40)
        );
    }

    protected function isImage($file): bool
    {
        return Str::startsWith(
            $file instanceof UploadedFile ? $file->getMimeType() : $file->type,
            'image/'
        );
    }

    protected function processImage(string $path): void
    {
        $this->processor->process($path, [
            'max_width' => 2000,
            'max_height' => 2000,
            'optimize' => true,
            'strip_metadata' => true
        ]);
    }

    protected function generateThumbnails(Media $media): void
    {
        if (!$this->isImage($media)) {
            return;
        }

        $sizes = config('media.thumbnail_sizes', [
            'small' => [200, 200],
            'medium' => [800, 800]
        ]);

        foreach ($sizes as $name => [$width, $height]) {
            $thumbPath = $this->getThumbnailPath($media->path, $name);
            
            $this->processor->createThumbnail(
                $media->path,
                $thumbPath,
                $width,
                $height
            );
        }
    }

    protected function deleteThumbnails(Media $media): void
    {
        if (!$this->isImage($media)) {
            return;
        }

        $sizes = array_keys(config('media.thumbnail_sizes', []));

        foreach ($sizes as $name) {
            $this->storage->delete(
                $this->getThumbnailPath($media->path, $name)
            );
        }
    }

    protected function getThumbnailPath(string $originalPath, string $size): string
    {
        return preg_replace(
            '/^(.+)(\.[^.]+)$/',
            sprintf('$1_%s$2', $size),
            $originalPath
        );
    }
}

class MediaProcessor 
{
    private ImageProcessor $processor;
    private SecurityService $security;
    private MonitoringService $monitor;

    public function process(string $path, array $options = []): void
    {
        try {
            $this->monitor->startProcessing($path);
            
            $image = $this->processor->open($path);
            
            if ($options['strip_metadata'] ?? true) {
                $image->stripMetadata();
            }
            
            if (isset($options['max_width'], $options['max_height'])) {
                $image->resize(
                    $options['max_width'],
                    $options['max_height'],
                    function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    }
                );
            }
            
            if ($options['optimize'] ?? true) {
                $image->optimize();
            }
            
            $image->save();
            
        } catch (\Exception $e) {
            $this->monitor->logProcessingError($path, $e);
            throw $e;
        } finally {
            $this->monitor->endProcessing($path);
        }
    }

    public function createThumbnail(
        string $sourcePath,
        string $targetPath,
        int $width,
        int $height
    ): void {
        try {
            $this->processor->open($sourcePath)
                ->fit($width, $height)
                ->optimize()
                ->save($targetPath);
                
        } catch (\Exception $e) {
            $this->monitor->logThumbnailError($sourcePath, $e);
            throw $e;
        }
    }
}