<?php

namespace App\Core\Metric\Services;

use App\Core\Metric\Models\Metric;
use App\Core\Metric\Repositories\MetricRepository;
use Illuminate\Support\Facades\Cache;

class MetricService
{
    public function __construct(
        private MetricRepository $repository,
        private MetricValidator $validator,
        private MetricAggregator $aggregator
    ) {}

    public function record(string $name, $value, array $tags = []): Metric
    {
        $this->validator->validate($name, $value, $tags);

        return $this->repository->create([
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'recorded_at' => now()
        ]);
    }

    public function increment(string $name, array $tags = []): void
    {
        $this->record($name, 1, $tags);
    }

    public function decrement(string $name, array $tags = []): void
    {
        $this->record($name, -1, $tags);
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        Cache::set("metrics:gauge:{$name}", [
            'value' => $value,
            'tags' => $tags,
            'timestamp' => now()
        ]);
    }

    public function getMetric(string $name, array $filters = []): Collection
    {
        return $this->repository->getMetric($name, $filters);
    }

    public function aggregate(string $name, string $function, array $filters = []): mixed
    {
        return $this->aggregator->aggregate($name, $function, $filters);
    }

    public function getTimeSeries(string $name, string $interval, array $filters = []): array
    {
        return $this->repository->getTimeSeries($name, $interval, $filters);
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }

    public function cleanup(int $days = 30): int
    {
        return $this->repository->cleanup($days);
    }
}
