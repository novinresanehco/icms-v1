<?php

namespace App\Core\Template\Analytics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnalyticsManager
{
    private Collection $trackers;
    private Collection $events;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(MetricsCollector $metrics, array $config = [])
    {
        $this->trackers = new Collection();
        $this->events = new Collection();
        $this->metrics = $metrics;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Track a page view
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    public function trackPageView(string $path, array $data = []): void
    {
        $event = new AnalyticsEvent('pageview', [
            'path' => $path,
            'title' => $data['title'] ?? null,
            'referrer' => request()->header('referer'),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()
        ]);

        $this->trackEvent($event);
    }

    /**
     * Track custom event
     *
     * @param AnalyticsEvent $event
     * @return void
     */
    public function trackEvent(AnalyticsEvent $event): void
    {
        $this->events->push($event);

        foreach ($this->trackers as $tracker) {
            try {
                $tracker->track($event);
            } catch (\Exception $e) {
                Log::error("Analytics tracking failed", [
                    'tracker' => get_class($tracker),
                    'event' => $event->getName(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Register analytics tracker
     *
     * @param AnalyticsTracker $tracker
     * @return void
     */
    public function registerTracker(AnalyticsTracker $tracker): void
    {
        $this->trackers->push($tracker);
    }

    /**
     * Get analytics data for period
     *
     * @param string $period
     * @return array
     */
    public function getAnalytics(string $period = '24h'): array
    {
        $cacheKey = "analytics:{$period}";

        return Cache::remember($cacheKey, 3600, function () use ($period) {
            return $this->metrics->getMetrics($period);
        });
    }

    /**
     * Generate tracking script
     *
     * @return string
     */
    public function generateTrackingScript(): string
    {
        $scripts = $this->trackers->map(function ($tracker) {
            return $tracker->getScript();
        })->filter()->implode("\n");

        return <<<HTML
        <script>
            window.analyticsConfig = {$this->getClientConfig()};
            {$scripts}
        </script>
        HTML;
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'track_authenticated_users' => true,
            'cookie_lifetime' => 30 * 24 * 60, // 30 days
            'sampling_rate' => 100 // percentage
        ];
    }

    /**
     * Get client-side configuration
     *
     * @return string
     */
    protected function getClientConfig(): string
    {
        return json_encode([
            'enabled' => $this->config['enabled'],
            'sampling_rate' => $this->config['sampling_rate']
        ]);
    }
}

class AnalyticsEvent
{
    private string $name;
    private array $data;
    private ?string $userId;
    private ?string $sessionId;

    public function __construct(string $name, array $data = [], ?string $userId = null)
    {
        $this->name = $name;
        $this->data = $data;
        $this->userId = $userId;
        $this->sessionId = session()->getId();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
}

abstract class AnalyticsTracker
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    abstract public function track(AnalyticsEvent $event): void;
    abstract public function getScript(): ?string;
}

class GoogleAnalyticsTracker extends AnalyticsTracker
{
    public function track(AnalyticsEvent $event): void
    {
        // Implementation for server-side GA tracking
    }

    public function getScript(): ?string
    {
        $id = $this->config['tracking_id'];
        
        return <<<HTML
        <!-- Global site tag (gtag.js) - Google Analytics -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{$id}');
        </script>
        HTML;
    }
}

class MetricsCollector
{
    private array $metrics = [];

    /**
     * Record metric
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function record(string $name, $value): void
    {
        $this->metrics[] = [
            'name' => $name,
            'value' => $value,
            'timestamp' => now()
        ];
    }

    /**
     * Get metrics for period
     *
     * @param string $period
     * @return array
     */
    public function getMetrics(string $period): array
    {
        $since = $this->getPeriodStart($period);
        
        return collect($this->metrics)
            ->filter(function ($metric) use ($since) {
                return $metric['timestamp'] >= $since;
            })
            ->groupBy('name')
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'average' => $group->avg('value'),
                    'max' => $group->max('value'),
                    'min' => $group->min('value')
                ];
            })
            ->toArray();
    }

    /**
     * Get period start time
     *
     * @param string $period
     * @return \Carbon\Carbon
     */
    protected function getPeriodStart(string $period): \Carbon\Carbon
    {
        switch ($period) {
            case '24h':
                return now()->subDay();
            case '7d':
                return now()->subWeek();
            case '30d':
                return now()->subMonth();
            default:
                return now()->subDay();
        }
    }
}

class PerformanceTracker extends AnalyticsTracker
{
    private array $timings = [];

    public function track(AnalyticsEvent $event): void
    {
        if ($event->getName() === 'performance') {
            $this->recordTiming(
                $event->getData()['metric'],
                $event->getData()['value']
            );
        }
    }

    public function getScript(): ?string
    {
        return <<<HTML
        <script>
            if (window.performance) {
                window.addEventListener('load', function() {
                    setTimeout(function() {
                        const timing = performance.timing;
                        const metrics = {
                            pageLoad: timing.loadEventEnd - timing.navigationStart,
                            domReady: timing.domContentLoadedEventEnd - timing.navigationStart,
                            firstPaint: performance.getEntriesByType('paint')[0]?.startTime
                        };
                        
                        // Send metrics to server
                        fetch('/analytics/performance', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(metrics)
                        });
                    }, 0);
                });
            }
        </script>
        HTML;
    }

    private function recordTiming(string $metric, float $value): void
    {
        $this->timings[] = [
            'metric' => $metric,
            'value' => $value,
            'timestamp' => now()
        ];
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Analytics\AnalyticsManager;
use App\Core\Template\Analytics\GoogleAnalyticsTracker;
use App\Core\Template\Analytics\PerformanceTracker;
use App\Core\Template\Analytics\MetricsCollector;

class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(AnalyticsManager::class, function ($app) {
            $manager = new AnalyticsManager(
                new MetricsCollector(),
                config('analytics')
            );

            // Register default trackers
            $manager->registerTracker(new GoogleAnalyticsTracker([
                'tracking_id' => config('analytics.google_analytics_id')
            ]));

            $manager->registerTracker(new PerformanceTracker());

            return $manager;
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Register middleware for automatic page view tracking
        $this->app['router']->pushMiddleware(\App\Http\Middleware\TrackPageViews::class);
    }
}
