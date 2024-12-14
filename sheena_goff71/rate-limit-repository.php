<?php

namespace App\Core\RateLimit\Repositories;

use App\Core\RateLimit\Models\RateLimit;
use Illuminate\Support\Collection;

class RateLimitRepository
{
    public function recordAttempt(string $key): void
    {
        $rateLimit = RateLimit::firstOrCreate(['key' => $key], [
            'attempts' => 0,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        $rateLimit->incrementAttempts();
    }

    public function recordExceeded(string $key, int $attempts): void
    {
        RateLimit::updateOrCreate(
            ['key' => $key],
            [
                'attempts' => $attempts,
                'exceeded_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'last_attempt_at' => now()
            ]
        );
    }

    public function getHistory(string $key): Collection
    {
        return RateLimit::byKey($key)
                       ->orderBy('last_attempt_at', 'desc')
                       ->get();
    }

    public function getStats(): array
    {
        return [
            'total_records' => RateLimit::count(),
            'exceeded_count' => RateLimit::exceeded()->count(),
            'recent_attempts' => RateLimit::recent()->sum('attempts'),
            'top_keys' => RateLimit::selectRaw('key, sum(attempts) as total_attempts')
                                 ->groupBy('key')
                                 ->orderByDesc('total_attempts')
                                 ->limit(10)
                                 ->get()
                                 ->pluck('total_attempts', 'key')
                                 ->toArray()
        ];
    }

    public function cleanup(int $days = 30): int
    {
        return RateLimit::where('last_attempt_at', '<', now()->subDays($days))->delete();
    }

    public function reset(string $key): bool
    {
        return RateLimit::byKey($key)->update([
            'attempts' => 0,
            'exceeded_at' => null,
            'last_attempt_at' => null
        ]) > 0;
    }
}
