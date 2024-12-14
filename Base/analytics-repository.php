<?php

namespace App\Core\Repositories;

use App\Models\Analytics;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class AnalyticsRepository extends AdvancedRepository
{
    protected $model = Analytics::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function trackPageView(string $url, ?string $referrer = null): void
    {
        $this->executeTransaction(function() use ($url, $referrer) {
            $this->create([
                'type' => 'pageview',
                'url' => $url,
                'referrer' => $referrer,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'user_id' => auth()->id(),
                'session_id' => session()->getId(),
                'created_at' => now()
            ]);

            $this->cache->tags('analytics')->flush();
        });
    }

    public function trackEvent(string $category, string $action, ?string $label = null, ?int $value = null): void
    {
        $this->executeTransaction(function() use ($category, $action, $label, $value) {
            $this->create([
                'type' => 'event',
                'category' => $category,
                'action' => $action,
                'label' => $label,
                'value' => $value,
                'url' => request()->url(),
                'user_id' => auth()->id(),
                'session_id' => session()->getId(),
                'created_at' => now()
            ]);

            $this->cache->tags('analytics')->flush();
        });
    }

    public function getPageViews(Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->executeQuery(function() use ($startDate, $endDate) {
            return $this->model
                ->where('type', 'pageview')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('DATE(created_at) as date, COUNT(*) as views')
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        });
    }

    public function getPopularPages(int $limit = 10): Collection
    {
        return $this->executeQuery(function() use ($limit) {
            return $this->cache->remember("analytics.popular_pages.{$limit}", function() use ($limit) {
                return $this->model
                    ->where('type', 'pageview')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->selectRaw('url, COUNT(*) as views')
                    ->groupBy('url')
                    ->orderByDesc('views')
                    ->limit($limit)
                    ->get();
            });
        });
    }
}
