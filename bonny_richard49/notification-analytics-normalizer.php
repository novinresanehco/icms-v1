<?php

namespace App\Core\Notification\Analytics\Normalizer;

class DataNormalizer
{
    private array $normalizers = [];
    private array $validators = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->initializeNormalizers();
        $this->initializeValidators();
    }

    public function normalize(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (isset($this->normalizers[$key])) {
                $normalized[$key] = $this->normalizers[$key]($value);
            } else {
                $normalized[$key] = $this->defaultNormalizer($value);
            }
        }
        return $normalized;
    }

    public function validateNormalization(array $normalized): bool
    {
        foreach ($normalized as $key => $value) {
            if (isset($this->validators[$key]) && !$this->validators[$key]($value)) {
                return false;
            }
        }
        return true;
    }

    private function initializeNormalizers(): void
    {
        $this->normalizers = [
            'timestamp' => function($value) {
                return is_numeric($value) ? (int)$value : strtotime($value);
            },

            'duration' => function($value) {
                return is_numeric($value) ? (float)$value : 0.0;
            },

            'user_id' => function($value) {
                return is_numeric($value) ? (int)$value : null;
            },

            'metrics' => function($value) {
                if (!is_array($value)) return [];
                return array_map(function($metric) {
                    return is_numeric($metric) ? (float)$metric : 0.0;
                }, $value);
            },

            'tags' => function($value) {
                if (!is_array($value)) return [];
                return array_map('strval', $value);
            }
        ];
    }

    private function initializeValidators(): void
    {
        $this->validators = [
            'timestamp' => function($value) {
                return is_int($value) && $value > 0;
            },

            'duration' => function($value) {
                return is_float($value) && $value >= 0;
            },

            'user_id' => function($value) {
                return is_int($value) || is_null($value);
            },

            'metrics' => function($value) {
                return is_array($value) && !empty($value);
            },

            'tags' => function($value) {
                return is_array($value);
            }
        ];
    }

    private function defaultNormalizer($value)
    {
        if (is_numeric($value)) {
            return is_float($value) ? (float)$value : (int)$value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }
}

class TimeSeriesNormalizer
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'interval' => 3600,
            'fill_gaps' => true,
            'aggregate_function' => 'avg'
        ], $config);
    }

    public function normalize(array $data, string $timestampField, string $valueField): array
    {
        $grouped = $this->groupByInterval($data, $timestampField);
        $normalized = $this->aggregateIntervals($grouped, $valueField);

        if ($this->config['fill_gaps']) {
            $normalized = $this->fillGaps($normalized);
        }

        return $normalized;
    }

    private function groupByInterval(array $data, string $timestampField): array
    {
        $grouped = [];
        foreach ($data as $item) {
            $interval = $this->getInterval($item[$timestampField]);
            if (!isset($grouped[$interval])) {
                $grouped[$interval] = [];
            }
            $grouped[$interval][] = $item;
        }
        return $grouped;
    }

    private function aggregateIntervals(array $grouped, string $valueField): array
    {
        $normalized = [];
        foreach ($grouped as $interval => $items) {
            $normalized[$interval] = $this->aggregate(
                array_column($items, $valueField),
                $this->config['aggregate_function']
            );
        }
        return $normalized;
    }

    private function fillGaps(array $normalized): array
    {
        if (empty($normalized)) {
            return [];
        }

        $timestamps = array_keys($normalized);
        $start = min($timestamps);
        $end = max($timestamps);
        $filled = [];

        for ($i = $start; $i <= $end; $i += $this->config['interval']) {
            $filled[$i] = $normalized[$i] ?? 0;
        }

        return $filled;
    }

    private function getInterval(int $timestamp): int
    {
        return floor($timestamp / $this->config['interval']) * $this->config['interval'];
    }

    private function aggregate(array $values, string $function)
    {
        switch ($function) {
            case 'sum':
                return array_sum($values);
            case 'avg':
                return !empty($values) ? array_sum($values) / count($values) : 0;
            case 'min':
                return !empty($values) ? min($values) : 0;
            case 'max':
                return !empty($values) ? max($values) : 0;
            case 'count':
                return count($values);
            default:
                throw new \InvalidArgumentException("Unsupported aggregate function: {$function}");
        }
    }
}
