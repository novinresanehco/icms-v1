<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;
use Carbon\Carbon;

interface AnalyticsRepositoryInterface extends RepositoryInterface
{
    public function trackPageView(array $data): bool;
    
    public function getDailyPageViews(Carbon $startDate, Carbon $endDate): Collection;
    
    public function getPopularPages(int $limit = 10, ?Carbon $startDate = null): Collection;
    
    public function getUserAnalytics(int $userId): Collection;
    
    public function trackEvent(string $event, array $data): bool;
    
    public function getEventStats(string $event, Carbon $startDate, Carbon $endDate): Collection;
}
