<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\MediaException;

class MediaManager
{
    private SecurityManager $security;
    private MediaRepository $repository;
    private FileValidator $validator;
    private VirusScanner $virusScanner;
    private AuditLogger $auditLogger;

    public function uploadMedia(array $fileData, array $context): MediaEntity
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpload($fileData),
            $context
        );
    }

    private function executeUpload(array $fileData): MediaEntity
    {
        $this->validator->validateFile($fileData);
        $this->virusScanner->scan($fileData['tmp_name']);

        return DB::transaction(function() use ($fileData) {
            $path = $this->storeFile($fileData);
            $media = $this->repository->create([
                'filename' => $fileData['name'],
                'mime_type' => $fileData['type'],
                'size' => $fileData['size'],
                'path' => $path,
                'checksum' => hash_file('sha256', $fileData['tmp_name'])
            ]);

            $this->generateThumbnails($media);
            $this->updateMediaCache($media);
            $this->auditLogger->logMediaUpload($media);

            return $media;
        });
    }

    public function deleteMedia(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            $context
        );
    }

    private function executeDelete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $media = $this->repository->findOrFail($id);
            
            Storage::delete([
                $media->path,
                ...$this->getThumbnailPaths($media)
            ]);

            $this->repository->delete($id);
            $this->invalidateMediaCache($media);
            $this->auditLogger->logMediaDeletion($media);

            return true;
        });
    }

    private function storeFile(array $fileData): string
    {
        $path = Storage::putFile(
            'media',
            $fileData['tmp_name'],
            'private'
        );

        if (!$path) {
            throw new MediaException('Failed to store file');
        }

        return $path;
    }

    private function generateThumbnails(MediaEntity $media): void
    {
        if (!$this->isImage($media->mime_type)) {
            return;
        }

        foreach (config('media.thumbnail_sizes') as $size) {
            $thumbnailPath = $this->generateThumbnail($media, $size);
            $this->repository->addThumbnail($media->id, $size, $thumbnailPath);
        }
    }

    private function generateThumbnail(MediaEntity $media, array $size): string
    {
        $image = $this->loadImage($media->path);
        $thumbnail = $this->resizeImage($image, $size);
        
        $path = "thumbnails/{$media->id}_{$size['width']}x{$size['height']}.jpg";
        
        if (!Storage::put($path, $thumbnail)) {
            throw new MediaException('Failed to store thumbnail');
        }

        return $path;
    }

    private function getThumbnailPaths(MediaEntity $media): array
    {
        return $media->thumbnails->pluck('path')->toArray();
    }

    private function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    private function updateMediaCache(MediaEntity $media): void
    {
        Cache::tags(['media'])->put(
            $this->getCacheKey($media->id),
            $media,
            now()->addDay()
        );
    }

    private function invalidateMediaCache(MediaEntity $media): void
    {
        Cache::tags(['media'])->forget($this->getCacheKey($media->id));
        Cache::tags(['media'])->forget('media_list');
    }

    private function getCacheKey(int $mediaId): string
    {
        return "media:{$mediaId}";
    }
}

class MediaRepository
{
    public function create(array $data): MediaEntity
    {
        return MediaEntity::create($data);
    }

    public function findOrFail(int $id): MediaEntity
    {
        return MediaEntity::with('thumbnails')->findOrFail($id);
    }

    public function delete(int $id): bool
    {
        return MediaEntity::destroy($id) > 0;
    }

    public function addThumbnail(int $mediaId, array $size, string $path): void
    {
        MediaThumbnail::create([
            'media_id' => $mediaId,
            'width' => $size['width'],
            'height' => $size['height'],
            'path' => $path
        ]);
    }
}

class MediaEntity extends Model
{
    protected $fillable = [
        'filename',
        'mime_type',
        'size',
        'path',
        'checksum'
    ];

    public function thumbnails()
    {
        return $this->hasMany(MediaThumbnail::class);
    }
}

class MediaThumbnail extends Model
{
    protected $fillable = [
        'media_id',
        'width',
        'height',
        'path'
    ];

    public function media()
    {
        return $this->belongsTo(MediaEntity::class);
    }
}
