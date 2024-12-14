<?php

namespace App\Repositories;

use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class MediaRepository implements MediaRepositoryInterface
{
    protected string $table = 'media';
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('cms.media.disk', 'public');
    }

    /**
     * Store new media file
     *
     * @param array $fileData File information and metadata
     * @return int|null Media ID if stored successfully, null on failure
     * @throws \InvalidArgumentException If required fields are missing
     */
    public function store(array $fileData): ?int
    {
        $this->validateFileData($fileData);

        try {
            DB::beginTransaction();

            $mediaId = DB::table($this->table)->insertGetId([
                'filename' => $fileData['filename'],
                'original_filename' => $fileData['original_filename'],
                'mime_type' => $fileData['mime_type'],
                'size' => $fileData['size'],
                'path' => $fileData['path'],
                'disk' => $this->disk,
                'user_id' => auth()->id(),
                'metadata' => json_encode($fileData['metadata'] ?? []),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();
            return $mediaId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to store media: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update media metadata
     *
     * @param int $mediaId
     * @param array $data
     * @return bool
     */
    public function update(int $mediaId, array $data): bool
    {
        try {
            return DB::table($this->table)
                ->where('id', $mediaId)
                ->update(array_merge($data, [
                    'updated_at' => now()
                ])) > 0;
        } catch (\Exception $e) {
            \Log::error('Failed to update media: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get media by ID
     *
     * @param int $mediaId
     * @return array|null
     */
    public function get(int $mediaId): ?array
    {
        try {
            $media = DB::table($this->table)
                ->where('id', $mediaId)
                ->first();

            return $media ? (array) $media : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get media: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete media
     *
     * @param int $mediaId
     * @return bool
     */
    public function delete(int $mediaId): bool
    {
        try {
            DB::beginTransaction();

            $media = $this->get($mediaId);
            if (!$media) {
                return false;
            }

            // Delete physical file
            if (Storage::disk($media['disk'])->exists($media['path'])) {
                Storage::disk($media['disk'])->delete($media['path']);
            }

            // Delete thumbnails if they exist
            $this->deleteThumbnails($media);

            // Delete database record
            $deleted = DB::table($this->table)
                ->where('id', $mediaId)
                ->delete() > 0;

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete media: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get paginated media list
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginated(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = DB::table($this->table);

        if (!empty($filters['type'])) {
            $query->where('mime_type', 'like', $filters['type'] . '%');
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('original_filename', 'like', "%{$search}%")
                  ->orWhere('filename', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')
                    ->paginate($perPage);
    }

    /**
     * Get media by type
     *
     * @param string $type MIME type prefix (e.g., 'image', 'video')
     * @return Collection
     */
    public function getByType(string $type): Collection
    {
        return collect(DB::table($this->table)
            ->where('mime_type', 'like', $type . '%')
            ->orderBy('created_at', 'desc')
            ->get());
    }

    /**
     * Get total storage usage
     *
     * @return int Total size in bytes
     */
    public function getTotalStorageUsage(): int
    {
        return (int) DB::table($this->table)->sum('size');
    }

    /**
     * Get user storage usage
     *
     * @param int $userId
     * @return int Total size in bytes
     */
    public function getUserStorageUsage(int $userId): int
    {
        return (int) DB::table($this->table)
            ->where('user_id', $userId)
            ->sum('size');
    }

    /**
     * Get unused media files
     *
     * @param int $days Number of days to consider media as unused
     * @return Collection
     */
    public function getUnusedMedia(int $days): Collection
    {
        return collect(DB::table($this->table)
            ->whereNull('last_used_at')
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->get());
    }

    /**
     * Update last used timestamp
     *
     * @param int $mediaId
     * @return bool
     */
    public function updateLastUsed(int $mediaId): bool
    {
        try {
            return DB::table($this->table)
                ->where('id', $mediaId)
                ->update([
                    'last_used_at' => now(),
                    'updated_at' => now()
                ]) > 0;
        } catch (\Exception $e) {
            \Log::error('Failed to update last used: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete thumbnails associated with media
     *
     * @param array $media
     * @return void
     */
    protected function deleteThumbnails(array $media): void
    {
        $thumbnailSizes = config('cms.media.thumbnail_sizes', []);
        $pathInfo = pathinfo($media['path']);

        foreach ($thumbnailSizes as $size) {
            $thumbnailPath = $pathInfo['dirname'] . '/' . 
                           $pathInfo['filename'] . 
                           "_{$size['width']}x{$size['height']}." . 
                           $pathInfo['extension'];

            if (Storage::disk($media['disk'])->exists($thumbnailPath)) {
                Storage::disk($media['disk'])->delete($thumbnailPath);
            }
        }
    }

    /**
     * Validate file data
     *
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateFileData(array $data): void
    {
        $required = ['filename', 'original_filename', 'mime_type', 'size', 'path'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if ($data['size'] <= 0) {
            throw new \InvalidArgumentException('Invalid file size');
        }
    }
}
