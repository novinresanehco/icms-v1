<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\MediaRepositoryInterface;
use App\Core\Models\Media;
use App\Core\Exceptions\MediaRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MediaRepository implements MediaRepositoryInterface
{
    protected Media $model;
    protected const CACHE_PREFIX = 'media:';
    protected const CACHE_TTL = 3600;

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function upload(UploadedFile $file, array $data = []): Model
    {
        try {
            DB::beginTransaction();

            $path = $this->storeFile($file);
            $metadata = $this->generateMetadata($file);

            $media = $this->model->create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'disk' => config('filesystems.default'),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'user_id' => auth()->id(),
                'title' => $data['title'] ?? $file->getClientOriginalName(),
                'alt_text' => $data['alt_text'] ?? null,
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'general',
                'metadata' => $metadata,
            ]);

            if ($this->isImage($file)) {
                $this->generateThumbnails($media, $file);
            }

            DB::commit();
            $this->clearCache();

            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to upload media: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $media = $this->findById($id);
            
            $media->update([
                'title' => $data['title'] ?? $media->title,
                'alt_text' => $data['alt_text'] ?? $media->alt_text,
                'description' => $data['description'] ?? $media->description,
                'category' => $data['category'] ?? $media->category,
                'metadata' => array_merge($media->metadata ?? [], $data['metadata'] ?? [])
            ]);

            DB::commit();
            $this->clearCache();

            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to update media: {$e->getMessage()}", 0, $e);
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->findOrFail($id)
        );
    }

    public function getByCategory(string $category): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "category:{$category}",
            self::CACHE_TTL,
            fn () => $this->model->where('category', $category)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function search(array $criteria): Collection
    {
        $query = $this->model->newQuery();

        if (isset($criteria['term'])) {
            $query->where(function ($q) use ($criteria) {
                $q->where('title', 'like', "%{$criteria['term']}%")
                  ->orWhere('filename', 'like', "%{$criteria['term']}%")
                  ->orWhere('description', 'like', "%{$criteria['term']}%");
            });
        }

        if (isset($criteria['mime_type'])) {
            $query->where('mime_type', 'like', $criteria['mime_type'] . '%');
        }

        if (isset($criteria['category'])) {
            $query->where('category', $criteria['category']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($criteria['per_page'] ?? 15);
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->findById($id);
            
            // Delete physical files
            Storage::disk($media->disk)->delete($media->path);
            $this->deleteThumbnails($media);

            $deleted = $media->delete();

            DB::commit();
            $this->clearCache();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaRepositoryException("Failed to delete media: {$e->getMessage()}", 0, $e);
        }
    }

    protected function storeFile(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $path = date('Y/m/d') . '/' . $filename;

        Storage::putFileAs(
            date('Y/m/d'),
            $file,
            $filename
        );

        return $path;
    }

    protected function generateMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
        ];

        if ($this->isImage($file)) {
            $image = Image::make($file);
            $metadata['dimensions'] = [
                'width' => $image->width(),
                'height' => $image->height(),
            ];
            $metadata['exif'] = $image->exif() ?? [];
        }

        return $metadata;
    }

    protected function generateThumbnails(Model $media, UploadedFile $file): void
    {
        $sizes = config('media.thumbnail_sizes', [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [600, 600]
        ]);

        $thumbnails = [];
        foreach ($sizes as $size => [$width, $height]) {
            $thumbnailPath = "thumbnails/{$size}/" . $media->path;
            
            $image = Image::make($file)
                ->fit($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });

            Storage::put($thumbnailPath, $image->encode());
            
            $thumbnails[$size] = $thumbnailPath;
        }

        $media->update(['thumbnails' => $thumbnails]);
    }

    protected function deleteThumbnails(Model $media): void
    {
        if (!empty($media->thumbnails)) {
            foreach ($media->thumbnails as $thumbnail) {
                Storage::disk($media->disk)->delete($thumbnail);
            }
        }
    }

    protected function isImage(UploadedFile $file): bool
    {
        return Str::startsWith($file->getMimeType(), 'image/');
    }

    protected function clearCache(): void
    {
        Cache::tags(['media'])->flush();
    }
}
