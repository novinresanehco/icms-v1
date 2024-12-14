<?php

namespace App\Core\Metric\Services;

use App\Core\Metric\Repositories\MetricRepository;

class MetricAggregator
{
    public function __construct(private MetricRepository $repository)
    {
    }

    public function aggregate(string $name, string $function, array $filters = []): mixed
    {
        $metrics = $this->repository->getMetric($name, $filters);

        return match($function) {
            'sum' => $metrics->sum('value'),
            'avg' => $metrics->avg('value'),
            'min' => $metrics->min('value'),
            'max' => $metrics->max('value'),
            'count' => $metrics->count(),
            default => throw new \InvalidArgumentException("Unknown aggregation function: {$function}")
        };
    }
}
