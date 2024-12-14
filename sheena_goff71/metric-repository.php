<?php

namespace App\Core\Metric\Repositories;

use App\Core\Metric\Models\Metric;
use Illuminate\Support\Collection;
use Carbon\CarbonPeriod;

class MetricRepository
{
    public function create(array $data): Metric
    {
        return Metric::create($data);
    }

    public function getMetric(string $name, array $filters = []): Collection
    {
        $query = Metric::byName($name);

        if (!empty($filters['tags'])) {
            $query->withTags($filters['tags']);
        }

        if (!empty($filters['start']) && !empty($filters['end'])) {
            $query->inTimeRange($filters['start'], $filters['end']);
        }

        if (!empty($filters['days'])) {
            $query->lastDays($filters['days']);
        }

        return $query->orderBy('recorded_at')->get();
    }

    public function getTimeSeries(string $name, string $interval, array $filters = []): array
    {
        $start = $filters['start'] ?? now()->subDay();
        $end = $filters['end'] ?? now();
        $period = CarbonPeriod::create($start, $interval, $end);

        $metrics = $this->getMetric($name, $filters)
            ->groupBy(function ($metric) use ($interval) {
                return $metric->recorded_at->startOf($interval);
            });

        $series = [];
        foreach ($period as $date) {
            $series[$date->format('Y-m-d H:i:s')] = $metrics->get($date)?->avg('value') ?? 0;
        }

        return $series;
    }

    public function getStats(): array
    {
        return [
            'total_metrics' => Metric::count(),
            'unique_names' => Metric::distinct('name')->count(),
            'by_name' => Metric::selectRaw('name, count(*) as count')
                              ->groupBy('name')
                              ->pluck('count', 'name')
                              ->toArray(),
            'recent_count' => Metric::lastDays(1)->count()
        ];
    }

    public function cleanup(int $days): int
    {
        return Metric::where('recorded_at', '<', now()->subDays($days))->delete();
    }
}
