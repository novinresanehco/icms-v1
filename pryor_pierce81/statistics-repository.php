<?php

namespace App\Core\Repository;

use App\Models\Statistic;
use App\Core\Events\StatisticEvents;
use App\Core\Exceptions\StatisticsRepositoryException;

class StatisticsRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Statistic::class;
    }

    /**
     * Record metric
     */
    public function recordMetric(string $name, $value, array $dimensions = []): void
    {
        try {
            $this->create([
                'name' => $name,
                'value' => $value,
                'dimensions' => $dimensions,
                'recorded_at' => now()
            ]);

            $this->clearCache();
            event(new StatisticEvents\MetricRecorded($name, $value, $dimensions));

        } catch (\Exception $e) {
            throw new StatisticsRepositoryException(
                "Failed to record metric: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get metric statistics
     */
    public function getMetricStats(string $name, array $options = []): array
    {
        try {
            $query = $this->model->where('name', $name);

            if (isset($options['from'])) {
                $query->where('recorded_at', '>=', $options['from']);
            }

            if (isset($options['to'])) {
                $query->where('recorded_at', '<=', $options['to']);
            }

            if (isset($options['dimensions'])) {
                foreach ($options['dimensions'] as $key => $value) {
                    $query->where("dimensions->{$key}", $value);
                }
            }

            $data = $query->get();

            return [
                'count' => $data->count(),
                'sum' => $data->sum('value'),
                'avg' => $data->avg('value'),
                'min' => $data->min('value'),
                'max' => $data->max('value'),
                'values' => $data->pluck('value')->toArray()
            ];

        } catch (\Exception $e) {
            throw new StatisticsRepositoryException(
                "Failed to get metric statistics: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get trending metrics
     */
    public function getTrendingMetrics(array $options = []): Collection
    {
        $period = $options['period'] ?? 'hour';
        $limit = $options['limit'] ?? 10;

        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("trending.{$period}.{$limit}"),
            300, // 5 minutes cache
            function() use ($period, $limit) {
                $query = $this->model
                    ->select('name')
                    ->selectRaw('COUNT(*) as count')
                    ->where('recorded_at', '>=', $this->getPeriodStart($period))
                    ->groupBy('name')
                    ->orderByDesc('count')
                    ->limit($limit);

                return $query->get();
            }
        );
    }

    /**
     * Get time series data
     */
    public function getTimeSeries(string $name, string $interval, array $options = []): Collection
    {
        try {
            $query = $this->model->where('name', $name);

            if (isset($options['from'])) {
                $query->where('recorded_at', '>=', $options['from']);
            }

            if (isset($options['to'])) {
                $query->where('recorded_at', '<=', $options['to']);
            }

            $groupFormat = match($interval) {
                'hour' => '%Y-%m-%d %H:00:00',
                'day' => '%Y-%m-%d',
                'month' => '%Y-%m',
                'year' => '%Y',
                default => throw new StatisticsRepositoryException("Invalid interval: {$interval}")
            };

            return $query
                ->select(DB::raw("DATE_FORMAT(recorded_at, '{$groupFormat}') as period"))
                ->selectRaw('COUNT(*) as count, AVG(value) as average, SUM(value) as total')
                ->groupBy('period')
                ->orderBy('period')
                ->get();

        } catch (\Exception $e) {
            throw new StatisticsRepositoryException(
                "Failed to get time series data: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get period start date
     */
    protected function getPeriodStart(string $period): Carbon
    {
        return match($period) {
            'hour' => now()->startOfHour(),
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => throw new StatisticsRepositoryException("Invalid period: {$period}")
        };
    }
}
