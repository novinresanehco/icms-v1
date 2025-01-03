<?php

namespace App\Core\Media;

class MediaService implements MediaServiceInterface
{
    protected StorageManager $storage;
    protected MediaRepository $repository;
    protected CacheManager $cache;
    protected ImageProcessor $processor;
    protected SecurityManager $security;

    public function store(UploadedFile $file, array $metadata = []): Media
    {
        return DB::transaction(function() use ($file, $metadata) {
            $path = $this->storage->store($file);
            $processed = $this->processor->process($path);
            
            $media = $this->repository->create([
                'path' => $path,
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => array_merge($metadata, $processed)
            ]);

            $this->cache->invalidate(['media', $media->id]);
            return $media;
        });
    }

    public function retrieve(int $id): Media
    {
        return $this->cache->remember(['media', $id], function() use ($id) {
            return $this->repository->findOrFail($id);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $media = $this->repository->findOrFail($id);
            $this->storage->delete($media->path);
            $this->repository->delete($id);
            $this->cache->invalidate(['media', $id]);
            return true;
        });
    }
}

class ImageProcessor implements ImageProcessorInterface 
{
    protected array $config;

    public function process(string $path): array
    {
        $image = $this->load($path);
        $metadata = $this->extractMetadata($image);
        $this->generateThumbnails($image, $path);
        return $metadata;
    }

    protected function generateThumbnails(Image $image, string $path): void
    {
        foreach ($this->config['sizes'] as $size => $dimensions) {
            $thumbnail = $image->resize($dimensions['width'], $dimensions['height']);
            $thumbnailPath = $this->getThumbnailPath($path, $size);
            $this->save($thumbnail, $thumbnailPath);
        }
    }

    protected function extractMetadata(Image $image): array
    {
        return [
            'width' => $image->getWidth(),
            'height' => $image->getHeight(),
            'format' => $image->getFormat(),
            'exif' => $this->getExifData($image)
        ];
    }
}

class StorageManager implements StorageManagerInterface
{
    protected DiskManager $disk;
    protected string $prefix;
    protected array $config;

    public function store(UploadedFile $file): string
    {
        $path = $this->generatePath($file);
        $this->disk->put($path, $file->getContent());
        return $path;
    }

    public function delete(string $path): bool
    {
        return $this->disk->delete($path);
    }

    protected function generatePath(UploadedFile $file): string
    {
        $hash = hash_file('sha256', $file->getPathname());
        $extension = $file->getClientOriginalExtension();
        return $this->prefix . '/' . substr($hash, 0, 2) . '/' . $hash . '.' . $extension;
    }
}

trait MediaCacheable
{
    protected function rememberMedia(string $key, callable $callback): mixed
    {
        return $this->cache->tags(['media'])->remember($key, function() use ($callback) {
            return $callback();
        }, $this->getCacheDuration());
    }

    protected function invalidateMedia(string $key): void
    {
        $this->cache->tags(['media'])->forget($key);
    }

    protected function getCacheDuration(): int
    {
        return config('cache.media_ttl', 3600);
    }
}
