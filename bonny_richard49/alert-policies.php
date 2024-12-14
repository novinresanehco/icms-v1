<?php

namespace App\Core\Notification\Analytics\Policies;

use App\Core\Notification\Analytics\Models\AlertConfiguration;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AlertThrottlingPolicy
{
    private const THROTTLE_CACHE_PREFIX = 'alert_throttle:';
    private const DEFAULT_THROTTLE_MINUTES = 30;

    public function shouldThrottleAlert(string $metricName, string $severity): bool
    {
        $cacheKey = $this->getThrottleCacheKey($metricName, $severity);
        
        if (Cache::has($cacheKey)) {
            return true;
        }

        $config = AlertConfiguration::forMetric($metricName)
            ->forSeverity($severity)
            ->first();

        if ($config && $config->shouldThrottle()) {
            Cache::put(
                $cacheKey,
                Carbon::now(),
                Carbon::now()->addMinutes($config->getThrottleDuration())
            );
        }

        return false;
    }

    public function getThrottleExpiryTime(string $metricName, string $severity): ?Carbon
    {
        $cacheKey = $this->getThrottleCacheKey($metricName, $severity);
        return Cache::get($cacheKey);
    }

    public function clearThrottle(string $metricName, string $severity): void
    {
        Cache::forget($this->getThrottleCacheKey($metricName, $severity));
    }

    private function getThrottleCacheKey(string $metricName, string $severity): string
    {
        return self::THROTTLE_CACHE_PREFIX . "{$metricName}:{$severity}";
    }
}

namespace App\Core\Notification\Analytics\Services;

class AlertPolicyService
{
    private array $severityLevels = [
        'critical' => 3,
        'warning' => 2,
        'info' => 1
    ];

    private array $channelPolicies = [
        'slack' => [
            'critical' => true,
            'warning' => true,
            'info' => false
        ],
        'email' => [
            'critical' => true,
            'warning' => true,
            'info' => false
        ],
        'sms' => [
            'critical' => true,
            'warning' => false,
            'info' => false
        ]
    ];

    public function