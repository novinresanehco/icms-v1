<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Media;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Interfaces\MediaRepositoryInterface;
use App\Services\ImageProcessor;

class MediaRepository implements MediaRepositoryInterface
{
    private const CACHE_PREFIX = 'media:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Media $model,
        private readonly ImageProcessor $imageProcessor,
        private readonly string $disk = 'public'
    ) {}

    public function findById(int $id): ?Media
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->find($id)
        );
    }

    public function create(UploadedFile $file, array $data = []): Media
    {
        $path = $this->storeFile($file);
        $mime = $file->getMimeType();
        
        $media = $this->model->create([
            'name' => $data['name'] ?? $file->getClientOriginalName(),
            'file_name' => basename($path),
            'mime_type' => $mime,
            'path' => $path,
            'disk' => $this->disk,
            'size' => $file->getSize(),
            'alt_text' => $data['alt_text'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'folder_id' => $data['folder_id'] ?? null,
            'user_id' => auth()->id()
        ]);

        if ($this->isImage($mime)) {
            $this->processImage($media, $file);
        }

        return $media;
    }

    public function update(int $id, array $data): bool
    {
        $media = $this->findById($id);
        
        if (!$media) {
            return false;
        }

        $updated = $media->update([
            'name' => $data['name'] ?? $media->name,
            'alt_text' => $data['alt_text'] ?? $media->alt_text,
            'title' => $data['title'] ?? $media->title,
            'description' => $data['description'] ?? $media->description,
            'folder_id' => $data['folder_id'] ?? $media->folder_id
        ]);

        if ($updated) {
            $this->clearCache($id);
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $media = $this->findById($id);
        
        if (!$media) {
            return false;
        }

        // Delete physical files
        Storage::disk($media->disk)->delete([
            $media->path,
            $this->getThumbnailPath($media->path),
            $this->getMediumPath($media->path)
        ]);

        $deleted = $media->delete();

        if ($deleted) {
            $this->clearCache($id);
        }

        return $deleted;
    }

    public function getByFolder(?int $folderId = null): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "folder:{$folderId}",
            self::CACHE_TTL,
            fn () => $this->model->where('folder_id', $folderId)->get()
        );
    }

    public function findByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('mime_type', 'LIKE', "{$type}/%")->get()
        );
    }

    protected function storeFile(UploadedFile $file): string
    {
        $path = date('Y/m/d');
        $name = Str::random(40) . '.' . $file->getClientOriginalExtension();
        
        return $file->storeAs($path, $name, [
            'disk' => $this->disk
        ]);
    }

    protected function processImage(Media $media, UploadedFile $file): void
    {
        // Generate thumbnail
        $this->imageProcessor->createThumbnail(
            $file->getRealPath(),
            $this->getThumbnailPath($media->path),
            200,
            200
        );

        // Generate medium size
        $this->imageProcessor->resize(
            $file->getRealPath(),
            $this->getMediumPath($media->path),
            800,
            800
        );
    }

    protected function isImage(string $mime): bool
    {
        return Str::startsWith($mime, 'image/');
    }

    protected function getThumbnailPath(string $path): string
    {
        return $this->getVariantPath($path, 'thumb');
    }

    protected function getMediumPath(string $path): string
    {
        return $this->getVariantPath($path, 'medium');
    }

    protected function getVariantPath(string $path, string $variant): string
    {
        $info = pathinfo($path);
        return $info['dirname'] . '/' . $info['filename'] . "_{$variant}." . $info['extension'];
    }

    protected function clearCache(int $id): void
    {
        Cache::forget(self::CACHE_PREFIX . $id);
        
        $media = $this->model->find($id);
        if ($media) {
            Cache::forget(self::CACHE_PREFIX . "folder:{$media->folder_id}");
            Cache::forget(self::CACHE_PREFIX . "type:" . explode('/', $media->mime_type)[0]);
        }
    }
}