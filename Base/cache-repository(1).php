<?php

namespace App\Repositories;

use App\Models\Cache;
use App\Repositories\Contracts\CacheRepositoryInterface;
use Illuminate\Support\Collection;

class CacheRepository extends BaseRepository implements CacheRepositoryInterface
{
    protected array $searchableFields = ['key', 'tags'];
    protected array $filterableFields = ['type'];

    public function getByTags(array $tags): Collection
    {
        return $this->model
            ->whereJsonContains('tags', $tags)
            ->get();
    }

    public function invalidateByTags(array $tags): void
    {
        $this->model
            ->whereJsonContains('tags', $tags)
            ->delete();
    }

    public function invalidateExpired(): int
    {
        return $this->model
            ->where('expires_at', '<', now())
            ->delete();
    }

    public function getCacheStats(): array
    {
        return [
            'total_entries' => $this->model->count(),
            'expired_entries' => $this->model->where('expires_at', '<', now())->count(),
            'total_size' => $this->model->sum('size'),
            'by_type' => $this->model->groupBy('type')->selectRaw('type, count(*) as count')->get()
        ];
    }
}
