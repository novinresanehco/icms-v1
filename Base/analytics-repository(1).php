<?php

namespace App\Repositories;

use App\Models\Analytics;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class AnalyticsRepository extends BaseRepository
{
    public function __construct(Analytics $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function recordPageView(array $data): Analytics
    {
        $analytics = $this->create([
            'page_url' => $data['url'],
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'referrer' => request()->header('referer'),
            'metadata' => $data['metadata'] ?? []
        ]);

        $this->clearCache();
        return $analytics;
    }

    public function getPopularPages(int $days = 30, int $limit = 10): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$days, $limit], function () use ($days, $limit) {
            return $this->model->where('created_at', '>=', now()->subDays($days))
                             ->select('page_url')
                             ->selectRaw('COUNT(*) as views')
                             ->groupBy('page_url')
                             ->orderByDesc('views')
                             ->limit($limit)
                             ->get();
        });
    }

    public function getVisitorStats(int $days = 30): array
    {
        return $this->executeWithCache(__FUNCTION__, [$days], function () use ($days) {
            $stats = $this->model->where('created_at', '>=', now()->subDays($days))
                                ->selectRaw('
                                    COUNT(*) as total_views,
                                    COUNT(DISTINCT ip_address) as unique_visitors,
                                    COUNT(DISTINCT user_id) as registered_users
                                ')
                                ->first();

            return $stats ? $stats->toArray() : [];
        });
    }

    public function getDailyStats(int $days = 30): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$days], function () use ($days) {
            return $this->model->where('created_at', '>=', now()->subDays($days))
                             ->selectRaw('DATE(created_at) as date, COUNT(*) as views')
                             ->groupBy('date')
                             ->orderBy('date')
                             ->get();
        });
    }
}
