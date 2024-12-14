<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{DB, Cache, Storage};
use App\Core\Security\SecurityManager;
use Illuminate\Http\UploadedFile;

class MediaManager implements MediaInterface 
{
    protected SecurityManager $security;
    protected MediaRepository $repository;
    protected ImageProcessor $processor;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        ImageProcessor $processor,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->processor = $processor;
        $this->config = $config;
    }

    public function upload(UploadedFile $file): MediaEntity 
    {
        return $this->security->executeCriticalOperation(function() use ($file) {
            return DB::transaction(function() use ($file) {
                $this->validateFile($file);
                
                $path = $this->storeFile($file);
                $hash = hash_file('sha256', $file->path());
                
                $media = $this->repository->create([
                    'path' => $path,
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'hash' => $hash
                ]);

                if ($this->isImage($file)) {
                    $this->generateThumbnails($media);
                }

                Cache::tags(['media'])->flush();
                
                return $media;
            });
        });
    }

    public function delete(int $id): bool 
    {
        return $this->security->executeCriticalOperation(function() use ($id) {
            return DB::transaction(function() use ($id) {
                $media = $this->repository->findOrFail($id);
                
                Storage::delete($media->path);
                $this->deleteThumbnails($media);
                
                $result = $this->repository->delete($id);
                Cache::tags(['media', "media.{$id}"])->flush();
                
                return $result;
            });
        });
    }

    public function get(int $id): ?MediaEntity 
    {
        return Cache::tags(["media.{$id}"])->remember(
            "media.{$id}",
            $this->config['cache_ttl'],
            fn() => $this->repository->find($id)
        );
    }

    public function attachToContent(int $contentId, array $mediaIds): void 
    {
        $this->security->executeCriticalOperation(function() use ($contentId, $mediaIds) {
            DB::transaction(function() use ($contentId, $mediaIds) {
                $this->repository->attachToContent($contentId, $mediaIds);
                Cache::tags(['content', "content.{$contentId}"])->flush();
            });
        });
    }

    public function detachFromContent(int $contentId): void 
    {
        $this->security->executeCriticalOperation(function() use ($contentId) {
            DB::transaction(function() use ($contentId) {
                $this->repository->detachFromContent($contentId);
                Cache::tags(['content', "content.{$contentId}"])->flush();
            });
        });
    }

    protected function validateFile(UploadedFile $file): void 
    {
        if (!in_array($file->getMimeType(), $this->config['allowed_mime_types'])) {
            throw new InvalidFileTypeException("File type not allowed");
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new FileTooLargeException("File exceeds maximum size");
        }
    }

    protected function storeFile(UploadedFile $file): string 
    {
        $directory = date('Y/m/d');
        return $file->store("media/{$directory}");
    }

    protected function isImage(UploadedFile $file): bool 
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }

    protected function generateThumbnails(MediaEntity $media): void 
    {
        foreach ($this->config['thumbnail_sizes'] as $size) {
            $thumbnailPath = $this->processor->createThumbnail(
                $media->path, 
                $size['width'], 
                $size['height']
            );
            
            $this->repository->addThumbnail($media->id, [
                'path' => $thumbnailPath,
                'width' => $size['width'],
                'height' => $size['height']
            ]);
        }
    }

    protected function deleteThumbnails(MediaEntity $media): void 
    {
        foreach ($media->thumbnails as $thumbnail) {
            Storage::delete($thumbnail->path);
        }
        
        $this->repository->deleteThumbnails($media->id);
    }
}
