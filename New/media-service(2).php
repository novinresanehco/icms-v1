<?php

namespace App\Core\Services;

use App\Core\Repositories\MediaRepository;
use App\Core\Security\AuditService;
use App\Exceptions\MediaException;
use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MediaService
{
    protected MediaRepository $repository;
    protected AuditService $auditService;
    protected array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    protected array $imageTypes = [
        'image/jpeg',
        'image/png',
        'image/gif'
    ];

    public function __construct(
        MediaRepository $repository,
        AuditService $auditService
    ) {
        $this->repository = $repository;
        $this->auditService = $auditService;
    }

    public function upload(UploadedFile $file, array $metadata = []): Media
    {
        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new MediaException('Invalid file type');
        }

        $path = $this->generatePath($file);
        $thumbnailPath = null;

        if ($this->isImage($file)) {
            $this->optimizeImage($file);
            $thumbnailPath = $this->generateThumbnail($file);
        }

        $hash = hash_file('sha256', $file->path());
        $this->detectDuplicate($hash);

        $media = $this->repository->create([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash,
            'metadata' => $metadata
        ]);

        $file->storeAs(
            dirname($path),
            basename($path),
            ['disk' => 'media']
        );

        $this->auditService->logSecurityEvent('media_uploaded', [
            'media_id' => $media->id,
            'filename' => $media->filename,
            'mime_type' => $media->mime_type
        ]);

        return $media;
    }

    public function delete(int $id): bool
    {
        $media = $this->repository->find($id);
        if (!$media) {
            throw new MediaException('Media not found');
        }

        Storage::disk('media')->delete($media->path);
        if ($media->thumbnail_path) {
            Storage::disk('media')->delete($media->thumbnail_path);
        }

        $result = $this->repository->delete($id);

        if ($result) {
            $this->auditService->logSecurityEvent('media_deleted', [
                'media_id' => $id,
                'filename' => $media->filename
            ]);
        }

        return $result;
    }

    protected function generatePath(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $name = Str::random(40);
        return date('Y/m/d') . "/{$name}.{$extension}";
    }

    protected function isImage(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), $this->imageTypes);
    }

    protected function optimizeImage(UploadedFile $file): void
    {
        $image = Image::make($file->path());
        
        // Resize if too large
        if ($image->width() > 2000 || $image->height() > 2000) {
            $image->resize(2000, 2000, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        // Optimize quality
        $image->save($file->path(), 85);
    }

    protected function generateThumbnail(UploadedFile $file): string
    {
        $image = Image::make($file->path());
        $thumbnailPath = dirname($this->generatePath($file)) . '/thumb_' . basename($file->path());

        $image->fit(300, 300)
            ->save(storage_path('app/media/' . $thumbnailPath), 80);

        return $thumbnailPath;
    }

    protected function detectDuplicate(string $hash): void
    {
        if ($this->repository->findByHash($hash)) {
            throw new MediaException('Duplicate file detected');
        }
    }
}
