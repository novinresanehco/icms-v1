<?php

namespace App\Core\Analytics;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\PerformanceTracker;

class AnalyticsManager implements AnalyticsInterface 
{
    protected SecurityManager $security;
    protected PerformanceTracker $performance;
    protected MetricsRepository $metrics;
    protected ReportGenerator $reports;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        PerformanceTracker $performance,
        MetricsRepository $metrics,
        ReportGenerator $reports,
        array $config
    ) {
        $this->security = $security;
        $this->performance = $performance;
        $this->metrics = $metrics;
        $this->reports = $reports;
        $this->config = $config;
    }

    public function track(string $event, array $data = []): void 
    {
        $this->security->executeCriticalOperation(function() use ($event, $data) {
            $this->validateEvent($event, $data);
            
            $metrics = [
                'event' => $event,
                'data' => $this->sanitizeData($data),
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now(),
                'session_id' => session()->getId(),
                'performance' => $this->performance->getCurrentMetrics()
            ];

            $this->metrics->store($metrics);
            $this->updateRealTimeMetrics($event, $metrics);
        });
    }

    public function generateReport(string $type, array $params = []): Report 
    {
        return $this->security->executeCriticalOperation(function() use ($type, $params) {
            $this->validateReportType($type);
            $cacheKey = $this->getReportCacheKey($type, $params);
            
            return Cache::tags(['analytics'])->remember($cacheKey, function() use ($type, $params) {
                $data = $this->aggregateData($type, $params);
                return $this->reports->generate($type, $data, $params);
            });
        });
    }

    public function getDashboardMetrics(): array 
    {
        return $this->security->executeCriticalOperation(function() {
            return Cache::tags(['analytics'])->remember('dashboard.metrics', function() {
                return [
                    'real_time' => $this->getRealTimeMetrics(),
                    'daily' => $this->getDailyMetrics(),
                    'weekly' => $this->getWeeklyMetrics(),
                    'monthly' => $this->getMonthlyMetrics()
                ];
            });
        });
    }

    protected function validateEvent(string $event, array $data): void 
    {
        if (!in_array($event, $this->config['allowed_events'])) {
            throw new InvalidEventException("Invalid event type: {$event}");
        }

        $validator = validator($data, $this->config['event_rules'][$event] ?? []);
        
        if ($validator->fails()) {
            throw new InvalidEventDataException($validator->errors()->first());
        }
    }

    protected function sanitizeData(array $data): array 
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    protected function sanitizeString(string $value): string 
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return substr($value, 0, $this->config['max_string_length']);
    }

    protected function updateRealTimeMetrics(string $event, array $metrics): void 
    {
        $key = "analytics.realtime.{$event}";
        
        Cache::tags(['analytics'])->put(
            $key,
            array_merge(
                Cache::tags(['analytics'])->get($key, []),
                [$metrics]
            ),
            now()->addMinutes($this->config['realtime_window'])
        );
    }

    protected function getRealTimeMetrics(): array 
    {
        $metrics = [];
        
        foreach ($this->config['allowed_events'] as $event) {
            $metrics[$event] = Cache::tags(['analytics'])->get(
                "analytics.realtime.{$event}",
                []
            );
        }

        return $metrics;
    }

    protected function getDailyMetrics(): array 
    {
        return Cache::tags(['analytics'])->remember('metrics.daily', function() {
            return $this->metrics->aggregateDaily(now()->subDays(30));
        });
    }

    protected function getWeeklyMetrics(): array 
    {
        return Cache::tags(['analytics'])->remember('metrics.weekly', function() {
            return $this->metrics->aggregateWeekly(now()->subWeeks(12));
        });
    }

    protected function getMonthlyMetrics(): array 
    {
        return Cache::tags(['analytics'])->remember('metrics.monthly', function() {
            return $this->metrics->aggregateMonthly(now()->subMonths(12));
        });
    }

    protected function aggregateData(string $type, array $params): array 
    {
        return match($type) {
            'performance' => $this->aggregatePerformanceData($params),
            'usage' => $this->aggregateUsageData($params),
            'security' => $this->aggregateSecurityData($params),
            'content' => $this->aggregateContentData($params),
            default => throw new UnsupportedReportTypeException()
        };
    }

    protected function getReportCacheKey(string $type, array $params): string 
    {
        return "analytics.report.{$type}." . md5(serialize($params));
    }

    protected function validateReportType(string $type): void 
    {
        if (!in_array($type, $this->config['allowed_report_types'])) {
            throw new InvalidReportTypeException("Invalid report type: {$type}");
        }
    }

    protected function aggregatePerformanceData(array $params): array 
    {
        return $this->metrics->aggregatePerformance(
            $params['start_date'] ?? now()->subDays(30),
            $params['end_date'] ?? now(),
            $params['interval'] ?? 'daily'
        );
    }

    protected function aggregateUsageData(array $params): array 
    {
        return $this->metrics->aggregateUsage(
            $params['start_date'] ?? now()->subDays(30),
            $params['end_date'] ?? now(),
            $params['group_by'] ?? ['event', 'user_id']
        );
    }

    protected function aggregateSecurityData(array $params): array 
    {
        return $this->metrics->aggregateSecurity(
            $params['start_date'] ?? now()->subDays(30),
            $params['end_date'] ?? now(),
            $params['include_events'] ?? ['login', 'access', 'error']
        );
    }

    protected function aggregateContentData(array $params): array 
    {
        return $this->metrics->aggregateContent(
            $params['start_date'] ?? now()->subDays(30),
            $params['end_date'] ?? now(),
            $params['content_types'] ?? ['page', 'post', 'media']
        );
    }
}
