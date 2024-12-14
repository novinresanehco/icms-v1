<?php

namespace App\Core\Notification\Analytics\Sampling;

class DataSampler
{
    private array $samplers = [];
    private array $metrics = [];

    public function registerSampler(string $name, callable $sampler): void 
    {
        $this->samplers[$name] = $sampler;
    }

    public function sample(array $data, string $sampler, array $options = []): array 
    {
        if (!isset($this->samplers[$sampler])) {
            throw new \InvalidArgumentException("Unknown sampler: {$sampler}");
        }

        $startTime = microtime(true);
        $originalSize = count($data);

        try {
            $sampled = ($this->samplers[$sampler])($data, $options);
            $this->recordMetrics($sampler, $originalSize, count($sampled), microtime(true) - $startTime, true);
            return $sampled;
        } catch (\Exception $e) {
            $this->recordMetrics($sampler, $originalSize, 0, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array 
    {
        return $this->metrics;
    }

    private function recordMetrics(string $sampler, int $originalSize, int $sampledSize, float $duration, bool $success): void 
    {
        if (!isset($this->metrics[$sampler])) {
            $this->metrics[$sampler] = [
                'total_runs' => 0,
                'successful_runs' => 0,
                'failed_runs' => 0,
                'total_duration' => 0,
                'total_input_size' => 0,
                'total_output_size' => 0,
                'average_reduction_ratio' => 0
            ];
        }

        $metrics = &$this->metrics[$sampler];
        $metrics['total_runs']++;
        $metrics[($success ? 'successful_runs' : 'failed_runs')]++;
        $metrics['total_duration'] += $duration;
        $metrics['total_input_size'] += $originalSize;
        
        if ($success) {
            $metrics['total_output_size'] += $sampledSize;
            $metrics['average_reduction_ratio'] = 
                1 - ($metrics['total_output_size'] / $metrics['total_input_size']);
        }
    }
}

class RandomSampler 
{
    public static function sample(array $data, array $options = []): array 
    {
        $rate = $options['rate'] ?? 0.1;
        $seed = $options['seed'] ?? null;

        if ($seed !== null) {
            mt_srand($seed);
        }

        return array_filter($data, function() use ($rate) {
            return mt_rand() / mt_getrandmax() < $rate;
        });
    }
}

class SystematicSampler 
{
    public static function sample(array $data, array $options = []): array 
    {
        $interval = $options['interval'] ?? 10;
        $offset = $options['offset'] ?? 0;
        
        return array_filter($data, function($key) use ($interval, $offset) {
            return ($key + $offset) % $interval === 0;
        }, ARRAY_FILTER_USE_KEY);
    }
}

class StratifiedSampler 
{
    public static function sample(array $data, array $options = []): array 
    {
        $strataKey = $options['strata_key'] ?? null;
        $rate = $options['rate'] ?? 0.1;

        if ($strataKey === null) {
            throw new \InvalidArgumentException("Strata key must be specified");
        }

        $strata = [];
        foreach ($data as $item) {
            $stratum = $item[$strataKey] ?? 'default';
            if (!isset($strata[$stratum])) {
                $strata[$stratum] = [];
            }
            $strata[$stratum][] = $item;
        }

        $sampled = [];
        foreach ($strata as $stratum => $items) {
            $sampleSize = max(1, (int)round(count($items) * $rate));
            $keys = array_rand($items, $sampleSize);
            if (!is_array($keys)) {
                $keys = [$keys];
            }
            foreach ($keys as $key) {
                $sampled[] = $items[$key];
            }
        }

        return $sampled;
    }
}

class WeightedSampler 
{
    public static function sample(array $data, array $options = []): array 
    {
        $weightKey = $options['weight_key'] ?? null;
        $targetSize = $options['target_size'] ?? null;

        if ($weightKey === null || $targetSize === null) {
            throw new \InvalidArgumentException("Weight key and target size must be specified");
        }

        $weights = array_map(function($item) use ($weightKey) {
            return $item[$weightKey] ?? 1;
        }, $data);

        $totalWeight = array_sum($weights);
        $sampledIndices = [];

        while (count($sampledIndices) < $targetSize && count($sampledIndices) < count($data)) {
            $r = mt_rand() / mt_getrandmax() * $totalWeight;
            $sum = 0;
            
            foreach ($weights as $index => $weight) {
                $sum += $weight;
                if ($sum >= $r && !in_array($index, $sampledIndices)) {
                    $sampledIndices[] = $index;
                    break;
                }
            }
        }

        return array_intersect_key($data, array_flip($sampledIndices));
    }
}
