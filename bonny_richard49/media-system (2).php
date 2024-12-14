<?php

namespace App\Core\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, DB};

class MediaManager
{
    private SecurityManager $security;
    private MediaRepository $repository;
    private MediaValidator $validator;
    private StorageManager $storage;
    private MediaProcessor $processor;

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        MediaValidator $validator,
        StorageManager $storage,
        MediaProcessor $processor
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->processor = $processor;
    }

    public function upload(UploadedFile $file): Media
    {
        return $this->security->protectedExecute(function() use ($file) {
            $this->validator->validateUpload($file);
            
            DB::beginTransaction();
            try {
                $path = $this->storage->store($file);
                $metadata = $this->processor->extractMetadata($file);
                
                $media = $this->repository->create([
                    'path' => $path,
                    'type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'metadata' => $metadata
                ]);
                
                $this->processor->process($media);
                
                DB::commit();
                return $media;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->storage->cleanup($path);
                throw $e;
            }
        });
    }

    public function get(int $id): ?Media
    {
        return $this->security->protectedExecute(
            fn() => $this->repository->find($id)
        );
    }

    public function delete(int $id): void
    {
        $this->security->protectedExecute(function() use ($id) {
            DB::beginTransaction();
            try {
                $media = $this->repository->find($id);
                if ($media) {
                    $this->storage->delete($media->path);
                    $this->repository->delete($id);
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }
}

class MediaValidator
{
    private array $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf'
    ];
    
    private int $maxSize = 10485760; // 10MB

    public function validateUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaValidationException('Invalid upload');
        }

        if (!in_array($file->getMimeType(), $this->allowedTypes)) {
            throw new MediaValidationException('File type not allowed');
        }

        if ($file->getSize() > $this->maxSize) {
            throw new MediaValidationException('File too large');
        }

        if (!$this->validateContent($file)) {
            throw new MediaValidationException('File content validation failed');
        }
    }

    private function validateContent(UploadedFile $file): bool
    {
        // Implement content validation based on file type
        return true;
    }
}

class StorageManager
{
    private string $disk = 'secure';
    private array $config;

    public function store(UploadedFile $file): string
    {
        $path = $this->generatePath($file);
        
        Storage::disk($this->disk)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );
        
        return $path;
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk)->delete($path);
    }

    public function cleanup(string $path): void
    {
        if (Storage::disk($this->disk)->exists($path)) {
            $this->delete($path);
        }
    }

    private function generatePath(UploadedFile $file): string
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $date = now()->format('Y/m/d');
        $name = $file->hashName();
        
        return "$date/$hash/$name";
    }
}

class MediaProcessor
{
    private ImageProcessor $imageProcessor;

    public function process(Media $media): void
    {
        if (str_starts_with($media->type, 'image/')) {
            $this->processImage($media);
        }
    }

    private function processImage(Media $media): void
    {
        $this->imageProcessor->optimize($media->path);
        $this->imageProcessor->generateThumbnails($media->path);
    }

    public function extractMetadata(UploadedFile $file): array
    {
        if (str_starts_with($file->getMimeType(), 'image/')) {
            return $this->extractImageMetadata($file);
        }
        
        return [];
    }

    private function extractImageMetadata(UploadedFile $file): array
    {
        // Extract image metadata
        return [];
    }
}

class MediaRepository
{
    public function create(array $data): Media
    {
        $id = DB::table('media')->insertGetId($data);
        return $this->find($id);
    }

    public function find(int $id): ?Media
    {
        $data = DB::table('media')->find($id);
        return $data ? new Media((array)$data) : null;
    }

    public function delete(int $id): bool
    {
        return DB::table('media')->delete($id) > 0;
    }
}

class Media
{
    public readonly int $id;
    public readonly string $path;
    public readonly string $type;
    public readonly int $size;
    public readonly array $metadata;

    public function __construct(array $attributes)
    {
        $this->id = $attributes['id'];
        $this->path = $attributes['path'];
        $this->type = $attributes['type'];
        $this->size = $attributes['size'];
        $this->metadata = $attributes['metadata'] ?? [];
    }
}

class MediaValidationException extends \Exception {}
