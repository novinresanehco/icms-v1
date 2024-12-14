<?php

namespace App\Core\Services;

use App\Core\Repositories\{StatisticsRepository, MetricsRepository, AnalyticsRepository};
use App\Core\Exceptions\StatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\{DB, Cache};

class StatisticsService extends BaseService
{
    protected MetricsRepository $metricsRepository;
    protected AnalyticsRepository $analyticsRepository;

    public function __construct(
        StatisticsRepository $repository,
        MetricsRepository $metricsRepository,
        AnalyticsRepository $analyticsRepository
    ) {
        parent::__construct($repository);
        $this->metricsRepository = $metricsRepository;
        $this->analyticsRepository = $analyticsRepository;
    }

    public function incrementStat(string $key, int $value = 1, array $metadata = []): void
    {
        try {
            $this->repository->increment($key, $value, $metadata);
        } catch (\Exception $e) {
            throw new StatException("Failed to increment stat: {$e->getMessage()}", 0, $e);
        }
    }

    public function trackStat(string $key, mixed $value, array $metadata = []): void
    {
        try {
            $this->repository->track($key, $value, $metadata);
        } catch (\Exception $e) {
            throw new StatException("Failed to track stat: {$e->getMessage()}", 0, $e);
        }
    }

    public function getStats(string $key, array $options = []): array
    {
        try {
            return $this->repository->getStats($key, $options);
        } catch (\Exception $e) {
            throw new StatException("Failed to get stats: {$e->getMessage()}", 0, $e);
        }
    }

    public function getTimeSeries(string $key, string $interval = '1 day'): Collection
    {
        try {
            return $this->repository->getTimeSeries($key, $interval);
        } catch (\Exception $e) {
            throw new StatException("Failed to get time series: {$e->getMessage()}", 0, $e);
        }
    }

    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        try {
            $this->metricsRepository->recordMetric($name, $value, $tags);
        } catch (\Exception $e) {
            throw new StatException("Failed to record metric: {$e->getMessage()}", 0, $e);
        }
    }

    public function getMetrics(string $name, array $filters = []): Collection
    {
        try {
            return $this->metricsRepository->getMetrics($name, $filters);
        } catch (\Exception $e) {
            throw new StatException("Failed to get metrics: {$e->getMessage()}", 0, $e);
        }
    }

    public function trackPageview(array $data): void
    {
        try {
            $this->analyticsRepository->trackPageview($data);
        } catch (\Exception $e) {
            throw new StatException("Failed to track pageview: {$e->getMessage()}", 0, $e);
        }
    }

    public function trackEvent(string $category, string $action, array $data = []): void
    {
        try {
            $this->analyticsRepository->trackEvent($category, $action, $data);
        } catch (\Exception $e) {
            throw new StatException("Failed to track event: {$e->getMessage()}", 0, $e);
        }
    }

    public function getPageviews(array $filters = []): Collection
    {
        try {
            return $this->analyticsRepository->getPageviews($filters);
        } catch (\Exception $e) {
            throw new StatException("Failed to get pageviews: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEvents(string $category, array $filters = []): Collection
    {
        try {
            return $this->analyticsRepository->getEvents($category, $filters);
        } catch (\Exception $e) {
            throw new StatException("Failed to get events: {$e->getMessage()}", 0, $e);
        }
    }
}
