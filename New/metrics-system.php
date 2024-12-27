<?php

namespace App\Core\Metrics;

interface MetricsCollectorInterface 
{
    public function record(array $metrics): void;
    public function increment(string $metric, int $amount = 1): void;
    public function gauge(string $metric, float $value): void;
    public function timing(string $metric, float $duration): void;
}

class MetricsCollector implements MetricsCollectorInterface
{
    private MetricsStorage $storage;
    private MetricsValidator $validator;

    public function __construct(MetricsStorage $storage, MetricsValidator $validator) 
    {
        $this->storage = $storage;
        $this->validator = $validator;
    }

    public function record(array $metrics): void
    {
        $this->validator->validate($metrics);

        $metrics['timestamp'] = $metrics['timestamp'] ?? now();
        $metrics['environment'] = config('app.env');

        $this->storage->store($metrics);
    }

    public function increment(string $metric, int $amount = 1): void
    {
        $this->record([
            'type' => 'counter',
            'name' => $metric,
            'value' => $amount
        ]);
    }

    public function gauge(string $metric, float $value): void
    {
        $this->record([
            'type' => 'gauge',
            'name' => $metric,
            'value' => $value
        ]);
    }

    public function timing(string $metric, float $duration): void
    {
        $this->record([
            'type' => 'timing',
            'name' => $metric,
            'value' => $duration
        ]);
    }
}

class MetricsStorage
{
    private DB $db;
    
    public function store(array $metrics): void
    {
        DB::table('metrics')->insert([
            'type' => $metrics['type'],
            'name' => $metrics['name'],
            'value' => $metrics['value'],
            'metadata' => json_encode($metrics['metadata'] ?? []),
            'environment' => $metrics['environment'],
            'created_at' => $metrics['timestamp']
        ]);
    }
    
    public function query(array $filters): array
    {
        return DB::table('metrics')
            ->when($filters['type'] ?? false, fn($q, $type) => 
                $q->where('type', $type))
            ->when($filters['name'] ?? false, fn($q, $name) => 
                $q->where('name', $name))
            ->when($filters['from'] ?? false, fn($q, $from) => 
                $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? false, fn($q, $to) => 
                $q->where('created_at', '<=', $to))
            ->orderBy('created_at', 'desc')
            ->limit(1000)
            ->get()
            ->toArray();
    }
}

class MetricsValidator
{
    public function validate(array $metrics): void
    {
        if (empty($metrics['type'])) {
            throw new ValidationException('Metric type is required');
        }

        if (empty($metrics['name'])) {
            throw new ValidationException('Metric name is required');
        }

        if (!isset($metrics['value'])) {
            throw new ValidationException('Metric value is required');
        }

        $this->validateType($metrics['type']);
        $this->validateName($metrics['name']);
        $this->validateValue($metrics['value']);
    }

    private function validateType(string $type): void
    {
        $validTypes = ['counter', 'gauge', 'timing'];
        
        if (!in_array($type, $validTypes)) {
            throw new ValidationException('Invalid metric type');
        }
    }

    private function validateName(string $name): void
    {
        if (strlen($name) > 100) {
            throw new ValidationException('Metric name too long');
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new ValidationException('Invalid metric name format');
        }
    }

    private function validateValue($value): void
    {
        if (!is_numeric($value)) {
            throw new ValidationException('Metric value must be numeric');
        }
    }
}

class MetricsAggregator
{
    private MetricsStorage $storage;

    public function aggregate(string $metric, string $aggregation, array $filters = []): float
    {
        $data = $this->storage->query(array_merge(
            $filters,
            ['name' => $metric]
        ));

        return match($aggregation) {
            'sum' => $this->sum($data),
            'avg' => $this->average($data),
            'min' => $this->minimum($data),
            'max' => $this->maximum($data),
            'p95' => $this->percentile($data, 95),
            'p99' => $this->percentile($data, 99),
            default => throw new \InvalidArgumentException('Invalid aggregation type')
        };
    }

    private function sum(array $data): float
    {
        return array_sum(array_column($data, 'value'));
    }

    private function average(array $data): float
    {
        if (empty($data)) return 0;
        return $this->sum($data) / count($data);
    }

    private function minimum(array $data): float
    {
        if (empty($data)) return 0;
        return min(array_column($data, 'value'));
    }

    private function maximum(array $data): float
    {
        if (empty($data)) return 0;
        return max(array_column($data, 'value'));
    }

    private function percentile(array $data, int $percentile): float
    {
        if (empty($data)) return 0;
        
        $values = array_column($data, 'value');
        sort($values);
        
        $index = ceil(($percentile / 100) * count($values)) - 1;
        return $values[$index];
    }
}