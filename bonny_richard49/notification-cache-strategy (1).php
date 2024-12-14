<?php

namespace App\Core\Notification\Analytics\Cache;

use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\Redis;
use App\Core\Notification\Analytics\Exceptions\CacheException;

class AnalyticsCacheStrategy
{
    private CacheManager $cacheManager;
    private array $config;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
        $this->config = config('analytics.cache');
    }

    public function rememberAnalytics(string $key, $ttl, callable $callback)
    {
        return $this->cacheManager->tags(['analytics', 'notifications'])
            ->remember($key, $ttl, $callback);
    }

    public function cacheReport(string $reportType, array $data, int $ttl = 3600)
    {
        $key = $this->buildReportKey($reportType);
        
        try {
            Redis::pipeline(function ($pipe) use ($key, $data, $ttl) {
                $pipe->setex($key, $ttl, serialize($data));
                $pipe->zadd('analytics_reports', time(), $reportType);
            });
        } catch (\Exception $e) {
            throw new CacheException("Failed to cache report: {$e->getMessage()}");
        }
    }

    public function getReport(string $reportType)
    {
        $key = $this->buildReportKey($reportType);
        
        try {
            $data = Redis::get($key);
            return $data ? unserialize($data) : null;
        } catch (\Exception $e) {
            throw new CacheException("Failed to retrieve report: {$e->getMessage()}");
        }
    }

    public function invalidateReport(string $reportType)
    {
        $key = $this->buildReportKey($reportType);
        
        try {
            Redis::pipeline(function ($pipe) use ($key, $reportType) {
                $pipe->del($key);
                $pipe->zrem('analytics_reports', $reportType);
            });
        } catch (\Exception $e) {
            throw new CacheException("Failed to invalidate report: {$e->getMessage()}");
        }
    }

    private function buildReportKey(string $reportType): string
    {
        return "analytics:reports:{$reportType}";
    }
}
