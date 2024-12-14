<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected array $searchableFields = ['filename', 'title', 'description', 'alt_text'];
    protected array $filterableFields = ['type', 'status', 'folder_id', 'user_id'];

    /**
     * Store new media file
     */
    public function storeMedia(UploadedFile $file, array $data = []): Media
    {
        $path = $file->store('media/' . date('Y/m'), 'public');
        
        $media = $this->create(array_merge($data, [
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'user_id' => auth()->id(),
            'metadata' => [
                'original_name' => $file->getClientOriginalName(),
                'dimensions' => $this->getImageDimensions($file),
                'uploaded_from' => request()->ip()
            ]
        ]));

        Cache::tags(['media'])->flush();

        return $media;
    }

    /**
     * Get media items by type
     */
    public function getByType(string $type, array $relations = []): Collection
    {
        $cacheKey = 'media.type.' . $type . '.' . md5(serialize($relations));

        return Cache::tags(['media'])->remember($cacheKey, 3600, function() use ($type, $relations) {
            return $this->model
                ->where('type', $type)
                ->with($relations)
                ->latest()
                ->get();
        });
    }

    /**
     * Get media items by folder
     */
    public function getByFolder(int $folderId, array $relations = []): Collection
    {
        $cacheKey = 'media.folder.' . $folderId . '.' . md5(serialize($relations));

        return Cache::tags(['media'])->remember($cacheKey, 3600, function() use ($folderId, $relations) {
            return $this->model
                ->where('folder_id', $folderId)
                ->with($relations)
                ->latest()
                ->get();
        });
    }

    /**
     * Update media metadata
     */
    public function updateMetadata(int $id, array $metadata): Media
    {
        $media = $this->find($id);
        
        $updatedMetadata = array_merge(
            $media->metadata ?? [],
            $metadata
        );

        $media = $this->update($id, [
            'metadata' => $updatedMetadata
        ]);

        Cache::tags(['media'])->flush();

        return $media;
    }

    /**
     * Delete media with file
     */
    public function delete(int $id): bool
    {
        $media = $this->find($id);

        if ($media && Storage::disk('public')->exists($media->path)) {
            Storage::disk('public')->delete($media->path);
        }

        $result = parent::delete($id);

        if ($result) {
            Cache::tags(['media'])->flush();
        }

        return $result;
    }

    /**
     * Duplicate media file
     */
    public function duplicate(int $id): ?Media
    {
        $original = $this->find($id);

        if (!$original) {
            return null;
        }

        $newPath = 'media/' . date('Y/m') . '/' . uniqid() . '_' . basename($original->path);

        if (Storage::disk('public')->exists($original->path)) {
            Storage::disk('public')->copy($original->path, $newPath);

            $duplicate = $this->create([
                'filename' => $original->filename,
                'path' => $newPath,
                'mime_type' => $original->mime_type,
                'size' => $original->size,
                'user_id' => auth()->id(),
                'folder_id' => $original->folder_id,
                'type' => $original->type,
                'title' => $original->title . ' (copy)',
                'description' => $original->description,
                'alt_text' => $original->alt_text,
                'metadata' => array_merge(
                    $original->metadata ?? [],
                    ['duplicated_from' => $original->id]
                )
            ]);

            Cache::tags(['media'])->flush();

            return $duplicate;
        }

        return null;
    }

    /**
     * Move media to folder
     */
    public function moveToFolder(int $id, ?int $folderId): bool
    {
        try {
            $this->update($id, ['folder_id' => $folderId]);
            Cache::tags(['media'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error moving media to folder: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get image dimensions if applicable
     */
    protected function getImageDimensions(UploadedFile $file): ?array
    {
        if (strpos($file->getMimeType(), 'image/') === 0) {
            try {
                [$width, $height] = getimagesize($file->getRealPath());
                return compact('width', 'height');
            } catch (\Exception $e) {
                \Log::warning('Could not get image dimensions: ' . $e->getMessage());
            }
        }

        return null;
    }
}
