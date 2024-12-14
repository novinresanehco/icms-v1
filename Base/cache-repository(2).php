<?php

namespace App\Repositories;

use App\Models\Cache;
use App\Repositories\Contracts\CacheRepositoryInterface;
use Illuminate\Support\Collection;

class CacheRepository extends BaseRepository implements CacheRepositoryInterface
{
    protected array $searchableFields = ['key', 'tags'];
    protected array $filterableFields = ['type'];

    public function clearByTags(array $tags): bool
    {
        try {
            DB::beginTransaction();
            $this->model->whereJsonContains('tags', $tags)->delete();
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function clearExpired(): bool
    {
        try {
            DB::beginTransaction();
            $this->model->where('expires_at', '<', now())->delete();
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function getStats(): array
    {
        return [
            'total_entries' => $this->model->count(),
            'expired_entries' => $this->model->where('expires_at', '<', now())->count(),
            'size' => $this->model->sum('size'),
            'hits' => $this->model->sum('hits'),
            'misses' => $this->model->sum('misses')
        ];
    }
}
