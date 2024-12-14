<?php

namespace App\Core\Repositories;

use App\Models\Media;
use App\Core\Services\Cache\CacheService;
use App\Core\Services\Storage\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class MediaRepository extends AdvancedRepository 
{
    protected $model = Media::class;
    protected $storage;
    protected $cache;

    public function __construct(StorageService $storage, CacheService $cache)
    {
        parent::__construct();
        $this->storage = $storage;
        $this->cache = $cache;
    }

    public function store(UploadedFile $file, array $metadata = []): Media
    {
        return $this->executeTransaction(function() use ($file, $metadata) {
            $path = $this->storage->store($file);
            
            return $this->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $metadata,
                'user_id' => auth()->id()
            ]);
        });
    }

    public function getByType(string $type): Collection
    {
        return $this->executeQuery(function() use ($type) {
            return $this->cache->remember("media.type.{$type}", function() use ($type) {
                return $this->model
                    ->where('mime_type', 'LIKE', $type . '/%')
                    ->orderBy('created_at', 'desc')
                    ->get();
            });
        });
    }

    public function delete(Media $media): bool
    {
        return $this->executeTransaction(function() use ($media) {
            $this->storage->delete($media->path);
            $this->cache->forget("media.{$media->id}");
            return parent::delete($media);
        });
    }

    public function updateMetadata(Media $media, array $metadata): void
    {
        $this->executeTransaction(function() use ($media, $metadata) {
            $media->update(['metadata' => array_merge($media->metadata, $metadata)]);
            $this->cache->forget("media.{$media->id}");
        });
    }
}
