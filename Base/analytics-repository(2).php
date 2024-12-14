<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Models\Analytics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsRepository extends BaseRepository implements AnalyticsRepositoryInterface
{
    public function __construct(Analytics $model)
    {
        parent::__construct($model);
    }

    public function trackPageView(array $data): bool
    {
        return $this->create([
            'type' => 'pageview',
            'url' => $data['url'],
            'session_id' => $data['session_id'],
            'user_id' => $data['user_id'] ?? null,
            'user_agent' => $data['user_agent'],
            'ip_address' => $data['ip_address'],
            'referrer' => $data['referrer'] ?? null,
            'metadata' => $this->extractMetadata($data)
        ]) instanceof Analytics;
    }

    public function getDailyPageViews(Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->model
            ->where('type', 'pageview')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as views')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    public function getPopularPages(int $limit = 10, ?Carbon $startDate = null): Collection
    {
        $query = $this->model
            ->where('type', 'pageview')
            ->select('url', DB::raw('COUNT(*) as views'))
            ->groupBy('url')
            ->orderByDesc('views')
            ->limit($limit);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        return $query->get();
    }

    public function getUserAnalytics(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('type');
    }

    public function trackEvent(string $event, array $data): bool
    {
        return $this->create([
            'type' => 'event',
            'event_name' => $event,
            'user_id' => $data['user_id'] ?? null,
            'session_id' => $data['session_id'],
            'metadata' => array_merge($data, [
                'timestamp' => now()->timestamp
            ])
        ]) instanceof Analytics;
    }

    public function getEventStats(string $event, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->model
            ->where('type', 'event')
            ->where('event_name', $event)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    protected function extractMetadata(array $data): array
    {
        return array_diff_key($data, array_flip([
            'url', 'session_id', 'user_id', 'user_agent', 
            'ip_address', 'referrer'
        ]));
    }
}
