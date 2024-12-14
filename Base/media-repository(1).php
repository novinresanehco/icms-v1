<?php

namespace App\Repositories;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MediaRepository extends BaseRepository implements MediaRepositoryInterface
{
    protected array $searchableFields = ['filename', 'title', 'alt_text', 'description'];
    protected array $filterableFields = ['type', 'status', 'folder_id', 'user_id'];

    /**
     * Store media file with metadata
     *
     * @param array $fileData
     * @param array $metadata
     * @return Media|null
     */
    public function storeMedia(array $fileData, array $metadata): ?Media
    {
        try {
            $path = Storage::disk('public')->putFile('media', $fileData['file']);
            
            $mediaData = array_merge($metadata, [
                'filename' => basename($path),
                'path' => $path,
                'mime_type' => $fileData['file']->getMimeType(),
                'size' => $fileData['file']->getSize(),
                'user_id' => auth()->id()
            ]);

            return $this->create($mediaData);
        } catch (\Exception $e) {
            \Log::error('Error storing media: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get media by type
     *
     * @param string $type
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('type', $type)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Get media by folder
     *
     * @param int $folderId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByFolder(int $folderId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('folder_id', $folderId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Move media to folder
     *
     * @param array $mediaIds
     * @param int|null $folderId
     * @return bool
     */
    public function moveToFolder(array $mediaIds, ?int $folderId): bool
    {
        try {
            $this->model->whereIn('id', $mediaIds)
                ->update(['folder_id' => $folderId]);
            return true;
        } catch (\Exception $e) {
            \Log::error('Error moving media to folder: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete media with file
     *
     * @param int $id
     * @return bool
     */
    public function deleteWithFile(int $id): bool
    {
        try {
            $media = $this->find($id);
            if (!$media) {
                return false;
            }

            Storage::disk('public')->delete($media->path);
            return $this->delete($id);
        } catch (\Exception $e) {
            \Log::error('Error deleting media: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get media usage statistics
     *
     * @return array
     */
    public function getMediaStats(): array
    {
        $cacheKey = 'media.stats';

        return Cache::tags(['media'])->remember($cacheKey, 300, function() {
            return [
                'total_size' => $this->model->sum('size'),
                'by_type' => $this->model->groupBy('type')
                    ->selectRaw('type, count(*) as count, sum(size) as total_size')
                    ->get()
                    ->keyBy('type')
                    ->toArray(),
                'recent_uploads' => $this->model->orderByDesc('created_at')
                    ->limit(5)
                    ->get(),
                'storage_usage' => [
                    'used' => $this->model->sum('size'),
                    'limit' => config('media.storage_limit')
                ]
            ];
        });
    }

    /**
     * Search media with advanced filters
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function($q) use ($searchTerm) {
                foreach ($this->searchableFields as $field) {
                    $q->orWhere($field, 'like', "%{$searchTerm}%");
                }
            });
        }

        foreach ($this->filterableFields as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['size_min'])) {
            $query->where('size', '>=', $filters['size_min']);
        }

        if (!empty($filters['size_max'])) {
            $query->where('size', '<=', $filters['size_max']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
