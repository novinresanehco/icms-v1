<?php

namespace App\Core\Audit\Filters;

class DataFilter
{
    private array $filters;
    private array $config;

    public function __construct(array $filters, array $config = [])
    {
        $this->filters = $filters;
        $this->config = $config;
    }

    public function filter(array $data): array
    {
        foreach ($this->filters as $filter) {
            if ($filter->supports($data)) {
                $data = $filter->apply($data);
            }
        }
        return $data;
    }
}

class RangeFilter
{
    private float $min;
    private float $max;

    public function __construct(float $min, float $max)
    {
        $this->min = $min;
        $this->max = $max;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && is_numeric($item),
            true
        );
    }

    public function apply(array $data): array
    {
        return array_filter(
            $data,
            fn($value) => $value >= $this->min && $value <= $this->max
        );
    }
}

class ThresholdFilter
{
    private float $threshold;
    private string $operator;

    public function __construct(float $threshold, string $operator = '>')
    {
        $this->threshold = $threshold;
        $this->operator = $operator;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && is_numeric($item),
            true
        );
    }

    public function apply(array $data): array
    {
        return array_filter(
            $data,
            fn($value) => match($this->operator) {
                '>' => $value > $this->threshold,
                '<' => $value < $this->threshold,
                '>=' => $value >= $this->threshold,
                '<=' => $value <= $this->threshold,
                '=' => $value == $this->threshold,
                '!=' => $value != $this->threshold,
                default => false
            }
        );
    }
}

class TimeRangeFilter
{
    private \DateTime $start;
    private \DateTime $end;
    private string $dateField;

    public function __construct(\DateTime $start, \DateTime $end, string $dateField = 'timestamp')
    {
        $this->start = $start;
        $this->end = $end;
        $this->dateField = $dateField;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && isset($item[$this->dateField]),
            true
        );
    }

    public function apply(array $data): array
    {
        return array_filter(
            $data,
            function($item) {
                $timestamp = $item[$this->dateField] instanceof \DateTime
                    ? $item[$this->dateField]
                    : new \DateTime($item[$this->dateField]);
                
                return $timestamp >= $this->start && $timestamp <= $this->end;
            }
        );
    }
}

class PatternFilter
{
    private string $pattern;
    private string $field;
    private bool $inverse;

    public function __construct(string $pattern, string $field, bool $inverse = false)
    {
        $this->pattern = $pattern;
        $this->field = $field;
        $this->inverse = $inverse;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && isset($item[$this->field]),
            true
        );
    }

    public function apply(array $data): array
    {
        return array_filter(
            $data,
            fn($item) => $this->inverse 
                ? !preg_match($this->pattern, (string)$item[$this->field])
                : preg_match($this->pattern, (string)$item[$this->field])
        );
    }
}
