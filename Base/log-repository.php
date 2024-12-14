<?php

namespace App\Repositories;

use App\Models\Log;
use App\Repositories\Contracts\LogRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class LogRepository extends BaseRepository implements LogRepositoryInterface
{
    protected array $searchableFields = ['message', 'context'];
    protected array $filterableFields = ['level', 'channel'];

    public function log(
        string $level,
        string $message,
        array $context = [],
        string $channel = 'application'
    ): Log {
        $log = $this->create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'channel' => $channel,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        if ($level === 'error' || $level === 'critical') {
            $this->notifyAdmins($log);
        }

        return $log;
    }

    public function getRecent(int $limit = 100, string $level = null): Collection
    {
        $cacheKey = "logs.recent.{$limit}" . ($level ? ".{$level}" : '');

        return Cache::tags(['logs'])->remember($cacheKey, 300, function() use ($limit, $level) {
            $query = $this->model->newQuery();

            if ($level) {
                $query->where('level', $level);
            }

            return $query->with('user')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    public function getByLevel(string $level, array $dateRange = []): Collection
    {
        $cacheKey = "logs.level.{$level}." . md5(serialize($dateRange));

        return Cache::tags(['logs'])->remember($cacheKey, 300, function() use ($level, $dateRange) {
            $query = $this->model->where('level', $level);

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return $query->with('user')
                ->orderByDesc('created_at')
                ->get();
        });
    }

    public function getByChannel(string $channel, array $dateRange = []): Collection
    {
        $cacheKey = "logs.channel.{$channel}." . md5(serialize($dateRange));

        return Cache::tags(['logs'])->remember($cacheKey, 300, function() use ($channel, $dateRange) {
            $query = $this->model->where('channel', $channel);

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return $query->with('user')
                ->orderByDesc('created_at')
                ->get();
        });
    }

    public function getUserLogs(int $userId): Collection
    {
        $cacheKey = "logs.user.{$userId}";

        return Cache::tags(['logs'])->remember($cacheKey, 300, function() use ($userId) {
            return $this->model
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->get();
        });
    }

    public function getLogStats(array $dateRange = []): array
    {
        $cacheKey = 'logs.stats.' . md5(serialize($dateRange));

        return Cache::tags(['logs'])->remember($cacheKey, 300, function() use ($dateRange) {
            $query = $this->model->newQuery();

            if (!empty($dateRange)) {
                $query->whereBetween('created_at', $dateRange);
            }

            return [
                'total_logs' => $query->count(),
                'by_level' => $query->groupBy('level')
                    ->selectRaw('level, count(*) as count')
                    ->pluck('count', 'level'),
                'by_channel' => $query->groupBy('channel')
                    ->selectRaw('channel, count(*) as count')
                    ->pluck('count', 'channel'),
                'error_rate' => $this->calculateErrorRate($dateRange)
            ];
        });
    }

    public function purgeOldLogs(int $days = 30): int
    {
        $count = $this->model
            ->where('created_at', '<', now()->subDays($days))
            ->where('level', '!=', 'error')
            ->where('level', '!=', 'critical')
            ->delete();

        if ($count > 0) {
            Cache::tags(['logs'])->flush();
        }

        return $count;
    }

    protected function notifyAdmins(Log $log): void
    {
        // Implement admin notification logic
        dispatch(new \App\Jobs\NotifyAdminsOfError($log));
    }

    protected function calculateErrorRate(array $dateRange = []): float
    {
        $query = $this->model->newQuery();

        if (!empty($dateRange)) {
            $query->whereBetween('created_at', $dateRange);
        }

        $totalLogs = $query->count();
        $errorLogs = $query->where('level', 'error')
            ->orWhere('level', 'critical')
            ->count();

        return $totalLogs > 0 ? ($errorLogs / $totalLogs) * 100 : 0;
    }
}
