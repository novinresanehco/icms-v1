<?php

namespace App\Core\Cache\Repositories;

use App\Core\Cache\Models\CacheEntry;
use Carbon\Carbon;

class CacheRepository
{
    public function recordCacheHit(string $key): void
    {
        CacheEntry::updateOrCreate(
            ['key' => $key],
            [
                'hits' => DB::raw('hits + 1'),
                'last_accessed_at' => now()
            ]
        );
    }

    public function getStats(): array
    {
        return [
            'total_entries' => CacheEntry::count(),
            'total_hits' => CacheEntry::sum('hits'),
            'most_accessed' => CacheEntry::orderBy('hits', 'desc')
                                      ->take(10)
                                      ->get()
                                      ->map(function ($entry) {
                                          return [
                                              'key' => $entry->key,
                                              'hits' => $entry->hits,
                                              'last_accessed' => $entry->last_accessed_at
                                          ];
                                      })
                                      ->toArray(),
            'recent_hits' => CacheEntry::where('last_accessed_at', '>=', Carbon::now()->subDay())
                                     ->count()
        ];
    }

    public function cleanup(int $days = 30): int
    {
        return CacheEntry::where('last_accessed_at', '<', Carbon::now()->subDays($days))
                        ->delete();
    }
}
