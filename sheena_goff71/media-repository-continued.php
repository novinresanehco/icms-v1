<?php

namespace App\Core\Media\Repositories;

use App\Core\Media\Models\Media;
use App\Core\Repository\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaRepository extends BaseRepository
{
    public function findByType(string $type, array $filters = []): Collection
    {
        $query = Media::query();

        if ($type === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($type === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        } else {
            $query->whereNotLike('mime_type', ['image/%', 'video/%']);
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%");
        }

        return $query->get();
    }

    public function paginateWithFilters(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Media::query();

        if (!empty($filters['type'])) {
            if ($filters['type'] === 'image') {
                $query->where('mime_type', 'like', 'image/%');
            } elseif ($filters['type'] === 'video') {
                $query->where('mime_type', 'like', 'video/%');
            } elseif ($filters['type'] === 'document') {
                $query->whereNotLike('mime_type', ['image/%', 'video/%']);
            }
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('file_name', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['mime_types'])) {
            $query->whereIn('mime_type', (array) $filters['mime_types']);
        }

        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        if (!empty($filters['min_size'])) {
            $query->where('size', '>=', $filters['min_size']);
        }

        if (!empty($filters['max_size'])) {
            $query->where('size', '<=', $filters['max_size']);
        }

        return $query->orderBy(
            $filters['sort_by'] ?? 'created_at',
            $filters['sort_direction'] ?? 'desc'
        )->paginate($perPage);
    }

    public function getByIds(array $ids): Collection
    {
        return Media::whereIn('id', $ids)->get();
    }

    public function findWithVariants(int $id): ?Media
    {
        return Media::with('variants')->find($id);
    }

    public function getUsageStats(Media $media): array
    {
        return [
            'total_usage' => $media->contents()->count(),
            'usage_by_type' => $media->contents()
                ->select('type', \DB::raw('count(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray()
        ];
    }

    public function getDuplicates(Media $media): Collection
    {
        return Media::where('file_name', $media->file_name)
                   ->where('id', '!=', $media->id)
                   ->get();
    }

    public function getUnused(): Collection
    {
        return Media::whereDoesntHave('contents')->get();
    }

    public function cleanupUnused(int $olderThanDays = 30): int
    {
        return Media::whereDoesntHave('contents')
                   ->where('created_at', '<=', now()->subDays($olderThanDays))
                   ->delete();
    }
}
