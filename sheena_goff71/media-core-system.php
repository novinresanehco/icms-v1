<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, Cache, Log};
use Illuminate\Http\UploadedFile;

class MediaManager
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected MediaRepository $repository;
    protected ImageProcessor $processor;

    public function store(UploadedFile $file): MediaResult
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->processUpload($file),
            ['action' => 'media.upload']
        );
    }

    protected function processUpload(UploadedFile $file): MediaResult
    {
        $this->validator->validateFile($file);
        
        $hash = hash_file('sha256', $file->path());
        
        if ($existing = $this->repository->findByHash($hash)) {
            return new MediaResult($existing);
        }

        $path = $file->store('media', ['disk' => 'secure']);
        
        $media = $this->repository->create([
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash
        ]);

        if ($this->isImage($file)) {
            $this->processor->generateThumbnails($media);
        }

        return new MediaResult($media);
    }

    protected function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }
}

class SecurityManager
{
    protected AuditLogger $audit;

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        if (!$this->validatePermissions($context)) {
            throw new UnauthorizedException();
        }

        try {
            $result = $operation();
            $this->audit->logOperation($context);
            return $result;
        } catch (\Throwable $e) {
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    protected function validatePermissions(array $context): bool
    {
        return auth()->user()?->can($context['action']) ?? false;
    }

    protected function handleFailure(\Throwable $e, array $context): void
    {
        Log::error('Media operation failed', [
            'error' => $e->getMessage(),
            'context' => $context
        ]);
    }
}

class ValidationService
{
    protected array $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    protected int $maxSize = 10485760; // 10MB

    public function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), $this->allowedMimes)) {
            throw new ValidationException('File type not allowed');
        }

        if ($file->getSize() > $this->maxSize) {
            throw new ValidationException('File size exceeds limit');
        }

        if (!$this->scanFile($file)) {
            throw new SecurityException('File failed security scan');
        }
    }

    protected function scanFile(UploadedFile $file): bool
    {
        // Implement virus scanning or other security checks
        return true;
    }
}

class MediaRepository
{
    public function findByHash(string $hash): ?Media
    {
        return Cache::remember(
            "media:hash:$hash",
            3600,
            fn() => Media::whereHash($hash)->first()
        );
    }

    public function create(array $data): Media
    {
        $media = Media::create($data);
        Cache::tags(['media'])->flush();
        return $media;
    }

    public function delete(int $id): bool
    {
        $media = Media::findOrFail($id);
        Storage::disk('secure')->delete($media->path);
        
        foreach ($media->thumbnails as $thumbnail) {
            Storage::disk('secure')->delete($thumbnail->path);
        }

        Cache::tags(['media'])->flush();
        return $media->delete();
    }
}

class ImageProcessor
{
    protected array $dimensions = [
        'thumb' => [150, 150],
        'medium' => [300, 300],
        'large' => [800, 800]
    ];

    public function generateThumbnails(Media $media): void
    {
        if (!str_starts_with($media->mime_type, 'image/')) {
            return;
        }

        foreach ($this->dimensions as $size => list($width, $height)) {
            $this->createThumbnail($media, $size, $width, $height);
        }
    }

    protected function createThumbnail(Media $media, string $size, int $width, int $height): void
    {
        $image = \Intervention\Image\Facades\Image::make(
            Storage::disk('secure')->path($media->path)
        );

        $image->fit($width, $height);

        $path = "thumbnails/{$media->id}_{$size}.jpg";
        Storage::disk('secure')->put($path, $image->encode('jpg'));

        $media->thumbnails()->create([
            'path' => $path,
            'size' => $size,
            'width' => $width,
            'height' => $height
        ]);
    }
}

class MediaResult
{
    public function __construct(
        public Media $media,
        public array $meta = []
    ) {}
}

class ValidationException extends \Exception {}
class SecurityException extends \Exception {}
class UnauthorizedException extends \Exception {}
