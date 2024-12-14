<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\MediaRepositoryInterface;
use App\Models\Media;
use App\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    /**
     * Cache TTL in seconds
     */
    protected const CACHE_TTL = 3600;

    /**
     * @param Media $model
     */
    public function __construct(Media $model)
    {
        parent::__construct($model);
    }

    /**
     * {@inheritDoc}
     */
    public function storeFile(UploadedFile $file, array $metadata = []): Media
    {
        try {
            DB::beginTransaction();

            $hash = hash_file('sha256', $file->path());

            // Check for duplicate
            if ($existingMedia = $this->findByHash($hash)) {
                return $existingMedia;
            }

            $path = $file->store('media/' . date('Y/m'), 'public');
            
            $media = $this->model->create([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'metadata' => json_encode($metadata),
                'user_id' => auth()->id()
            ]);

            if ($this->isImage($file->getMimeType())) {
                $this->generateThumbnails($media->id, [
                    'small' => [200, 200],
                    'medium' => [400, 400]
                ]);
            }

            DB::commit();

            $this->clearMediaCache();

            return $media;
        } catch (QueryException $e) {
            DB::rollBack();
            throw new MediaException("Error storing file: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeFromUrl(string $url, array $metadata = []): Media
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'media_');
            file_put_contents($tempFile, file_get_contents($url));

            $uploadedFile = new UploadedFile(
                $tempFile,
                basename($url),
                mime_content_type($tempFile),
                null,
                true
            );

            return $this->storeFile($uploadedFile, $metadata);
        } catch (\Exception $e) {
            throw new MediaException("Error storing file from URL: {$e->getMessage()}");
        } finally {
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getByType(string $type): Collection
    {
        return Cache::remember("media:type:{$type}", self::CACHE_TTL, function () use ($type) {
            return $this->model->where('type', 'like', $type . '%')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(int $mediaId): Collection
    {
        return Cache::remember("media:usage:{$mediaId}", self::CACHE_TTL, function () use ($mediaId) {
            return DB::table('media_usage')
                ->where('media_id', $mediaId)
                ->get();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function updateMetadata(int $mediaId, array $metadata): Media
    {
        try {
            $media = $this->findById($mediaId);
            if (!$media) {
                throw new MediaException("Media not found with ID: {$mediaId}");
            }

            $existingMetadata = json_decode($media->metadata, true) ?? [];
            $newMetadata = array_merge($existingMetadata, $metadata);

            $media->update(['metadata' => json_encode($newMetadata)]);

            $this->clearMediaCache();

            return $media->fresh();
        } catch (QueryException $e) {
            throw new MediaException("Error updating metadata: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function moveToFolder(int $mediaId, string $folder): Media
    {
        try {
            DB::beginTransaction();

            $media = $this->findById($mediaId);
            if (!$media) {
                throw new MediaException("Media not found with ID: {$mediaId}");
            }

            $newPath = $folder . '/' . basename($media->path);
            Storage::disk('public')->move($media->path, $newPath);

            $media->update(['path' => $newPath]);

            DB::commit();

            $this->clearMediaCache();

            return $media->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException("Error moving media: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function generateThumbnails(int $mediaId, array $sizes): Media
    {
        try {
            $media = $this->findById($mediaId);
            if (!$media) {
                throw new MediaException("Media not found with ID: {$mediaId}");
            }

            if (!$this->isImage($media->type)) {
                throw new MediaException("Media is not an image");
            }

            $thumbnails = [];
            foreach ($sizes as $name => $dimensions) {
                $thumbnailPath = 'thumbnails/' . $name . '_' . basename($media->path);
                
                $img = Image::make(Storage::disk('public')->path($media->path));
                $img->fit($dimensions[0], $dimensions[1]);
                
                Storage::disk('public')->put($thumbnailPath, $img->encode());
                
                $thumbnails[$name] = $thumbnailPath;
            }

            $metadata = json_decode($media->metadata, true) ?? [];
            $metadata['thumbnails'] = $thumbnails;

            $media->update(['metadata' => json_encode($metadata)]);

            return $media->fresh();
        } catch (\Exception $e) {
            throw new MediaException("Error generating thumbnails: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?Media
    {
        return Cache::remember("media:hash:{$hash}", self::CACHE_TTL, function () use ($hash) {
            return $this->model->where('hash', $hash)->first();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $hash): bool
    {
        return Cache::remember("media:exists:{$hash}", self::CACHE_TTL, function () use ($hash) {
            return $this->model->where('hash', $hash)->exists();
        });
    }

    /**
     * Check if mime type is an image
     *
     * @param string $mimeType
     * @return bool
     */
    protected function isImage(string $mimeType): bool
    {
        return Str::startsWith($mimeType, 'image/');
    }

    /**
     * Clear media cache
     *
     * @return void
     */
    protected function clearMediaCache(): void
    {
        Cache::tags(['media'])->flush();
    }
}
