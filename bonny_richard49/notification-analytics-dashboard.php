<?php

namespace App\Core\Notification\Analytics\Dashboard;

use App\Core\Notification\Analytics\NotificationAnalytics;
use App\Core\Notification\Analytics\RealTime\RealTimeProcessor;
use App\Core\Notification\Analytics\Cache\AnalyticsCacheStrategy;

class DashboardDataTransformer
{
    private NotificationAnalytics $analytics;
    private RealTimeProcessor $realTimeProcessor;
    private AnalyticsCacheStrategy $cache;

    public function __construct(
        NotificationAnalytics $analytics,
        RealTimeProcessor $realTimeProcessor,
        AnalyticsCacheStrategy $cache
    ) {
        $this->analytics = $analytics;
        $this->realTimeProcessor = $realTimeProcessor;
        $this->cache = $cache;
    }

    public function getOverviewData(): array
    {
        return $this->cache->rememberAnalytics('dashboard.overview', 300, function () {
            $performance = $this->analytics->analyzePerformance(['period' => 'today']);
            $realTime = $this->realTimeProcessor->calculateMetricStats('notifications.sent');

            return [
                'total_sent'