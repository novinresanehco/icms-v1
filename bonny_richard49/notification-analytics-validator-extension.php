<?php

namespace App\Core\Notification\Analytics\Validation;

class AnalyticsValidator
{
    private const MAX_BATCH_SIZE = 1000;
    private const ALLOWED_AGGREGATIONS = ['sum', 'avg', 'min', 'max', 'count'];
    private const MIN_DATE_RANGE = 1;
    private const MAX_DATE_RANGE = 365;

    public function validateRequest(array $params): void
    {
        $this->validateBatchSize($params['batch_size'] ?? null);
        $this->validateDateRange($params['start_date'] ?? null, $params['end_date'] ?? null);
        $this->validateAggregations($params['aggregations'] ?? []);
        $this->validateFilters($params['filters'] ?? []);
        $this->validateMetrics($params['metrics'] ?? []);
    }

    private function validateBatchSize(?int $batchSize): void
    {
        if ($batchSize !== null && ($batchSize <= 0 || $batchSize > self::MAX_BATCH_SIZE)) {
            throw new ValidationException("Batch size must be between 1 and " . self::MAX_BATCH_SIZE);
        }
    }

    private function validateDateRange(?string $startDate, ?string $endDate): void
    {
        if (!$startDate || !$endDate) {
            throw new ValidationException("Start date and end date are required");
        }

        $start = strtotime($startDate);
        $end = strtotime($endDate);

        if (!$start || !$end) {
            throw new ValidationException("Invalid date format");
        }

        $daysDiff = ($end - $start) / (60 * 60 * 24);

        if ($daysDiff < self::MIN_DATE_RANGE || $daysDiff > self::MAX_DATE_RANGE) {
            throw new ValidationException(
                "Date range must be between " . self::MIN_DATE_RANGE . 
                " and " . self::MAX_DATE_RANGE . " days"
            );
        }
    }

    private function validateAggregations(array $aggregations): void
    {
        foreach ($aggregations as $aggregation) {
            if (!in_array($aggregation, self::ALLOWED_AGGREGATIONS)) {
                throw new ValidationException(
                    "Invalid aggregation type: {$aggregation}. " .
                    "Allowed types: " . implode(', ', self::ALLOWED_AGGREGATIONS)
                );
            }
        }
    }

    private function validateFilters(array $filters): void
    {
        foreach ($filters as $filter) {
            if (!isset($filter['field'], $filter['operator'], $filter['value'])) {
                throw new ValidationException("Invalid filter format");
            }

            $this->validateFilterOperator($filter['operator']);
            $this->validateFilterValue($filter['value']);
        }
    }

    private function validateMetrics(array $metrics): void
    {
        $allowedMetrics = [
            'delivery_rate',
            'open_rate',
            'click_rate',
            'bounce_rate',
            'conversion_rate',
            'response_time'
        ];

        foreach ($metrics as $metric) {
            if (!in_array($metric, $allowedMetrics)) {
                throw new ValidationException(
                    "Invalid metric: {$metric}. " .
                    "Allowed metrics: " . implode(', ', $allowedMetrics)
                );
            }
        }
    }

    private function validateFilterOperator(string $operator): void
    {
        $allowedOperators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'nin', 'like'];

        if (!in_array($operator, $allowedOperators)) {
            throw new ValidationException(
                "Invalid filter operator: {$operator}. " .
                "Allowed operators: " . implode(', ', $allowedOperators)
            );
        }
    }

    private function validateFilterValue($value): void
    {
        if (is_array($value)) {
            if (count($value) > 100) {
                throw new ValidationException("Filter array value cannot exceed 100 items");
            }
            
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    throw new ValidationException("Filter array can only contain simple values");
                }
            }
        } elseif (!is_scalar($value)) {
            throw new ValidationException("Filter value must be a simple value or array");
        }
    }
}
