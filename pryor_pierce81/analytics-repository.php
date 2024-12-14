<?php

namespace App\Core\Repository;

use App\Models\Analytics;
use App\Core\Events\AnalyticsEvents;
use App\Core\Exceptions\AnalyticsRepositoryException;

class AnalyticsRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Analytics::class;
    }

    /**
     * Record page view
     */
    public function recordPageView(array $data): void
    {
        try {
            $analytics = $this->create([
                'type' => 'page_view',
                'data' => $data,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);

            event(new AnalyticsEvents\PageViewRecorded($analytics));
        } catch (\Exception $e) {
            throw new AnalyticsRepositoryException(
                "Failed to record page view: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get page views for time period
     */
    public function getPageViews(string $period = 'day'): Collection
    {
        $date = match($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => throw new AnalyticsRepositoryException("Invalid period: {$period}")
        };

        return $this->model->where('type', 'page_view')
                          ->where('created_at', '>=', $date)
                          ->get();
    }

    /**
     * Get popular content
     */
    public function getPopularContent(int $limit = 10): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('popular', $limit),
            300, // 5 minutes cache
            fn() => $this->model->where('type', 'page_view')
                               ->where('created_at', '>=', now()->subDays(30))
                               ->select('data->url', DB::raw('count(*) as views'))
                               ->groupBy('data->url')
                               ->orderByDesc('views')
                               ->limit($limit)
                               ->get()
        );
    }

    /**
     * Get visitor statistics
     */
    public function getVisitorStats(string $period = 'day'): array
    {
        $date = match($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => throw new AnalyticsRepositoryException("Invalid period: {$period}")
        };

        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('stats', $period),
            300, // 5 minutes cache
            fn() => [
                'total_visits' => $this->model->where('type', 'page_view')
                                            ->where('created_at', '>=', $date)
                                            ->count(),
                'unique_visitors' => $this->model->where('type', 'page_view')
                                               ->where('created_at', '>=', $date)
                                               ->distinct('ip')
                                               ->count('ip'),
                'average_duration' => $this->model->where('type', 'page_view')
                                                ->where('created_at', '>=', $date)
                                                ->avg('data->duration') ?? 0
            ]
        );
    }
}
