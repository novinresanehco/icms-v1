<?php

namespace App\Core\Notification\Analytics\Filter;

class AnalyticsFilter
{
    private array $filters = [];
    private array $processors = [];

    public function addFilter(string $name, callable $filter): void
    {
        $this->filters[$name] = $filter;
    }

    public function addProcessor(string $name, callable $processor): void
    {
        $this->processors[$name] = $processor;
    }

    public function apply(array $data, array $filterConfig): array
    {
        $result = $data;

        foreach ($filterConfig as $filter => $config) {
            if (isset($this->filters[$filter])) {
                $result = ($this->filters[$filter])($result, $config);
            }
        }

        return $result;
    }

    public function process(array $data, array $processingConfig): array
    {
        $result = $data;

        foreach ($processingConfig as $processor => $config) {
            if (isset($this->processors[$processor])) {
                $result = ($this->processors[$processor])($result, $config);
            }
        }

        return $result;
    }
}

class FilterBuilder
{
    private array $conditions = [];
    private array $transformations = [];

    public function where(string $field, string $operator, $value): self
    {
        $this->conditions[] = [
            'type' => 'where',
            'field' => $field,
            'operator' => $operator,
            'value' => $value
        ];
        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        $this->conditions[] = [
            'type' => 'whereIn',
            'field' => $field,
            'values' => $values
        ];
        return $this;
    }

    public function whereBetween(string $field, $min, $max): self
    {
        $this->conditions[] = [
            'type' => 'whereBetween',
            'field' => $field,
            'min' => $min,
            'max' => $max
        ];
        return $this;
    }

    public function transform(string $field, callable $transformer): self
    {
        $this->transformations[] = [
            'field' => $field,
            'transformer' => $transformer
        ];
        return $this;
    }

    public function build(): callable
    {
        $conditions = $this->conditions;
        $transformations = $this->transformations;

        return function(array $data) use ($conditions, $transformations) {
            return array_filter($data, function($item) use ($conditions) {
                foreach ($conditions as $condition) {
                    if (!$this->evaluateCondition($item, $condition)) {
                        return false;
                    }
                }
                return true;
            });
        };
    }

    private function evaluateCondition(array $item, array $condition): bool
    {
        $field = $condition['field'];
        if (!isset($item[$field])) {
            return false;
        }

        switch ($condition['type']) {
            case 'where':
                return $this->evaluateWhereCondition($item[$field], $condition);
            case 'whereIn':
                return in_array($item[$field], $condition['values']);
            case 'whereBetween':
                return $item[$field] >= $condition['min'] && $item[$field] <= $condition['max'];
            default:
                return false;
        }
    }

    private function evaluateWhereCondition($value, array $condition): bool
    {
        switch ($condition['operator']) {
            case '=':
                return $value === $condition['value'];
            case '!=':
                return $value !== $condition['value'];
            case '>':
                return $value > $condition['value'];
            case '>=':
                return $value >= $condition['value'];
            case '<':
                return $value < $condition['value'];
            case '<=':
                return $value <= $condition['value'];
            default:
                return false;
        }
    }
}
