<?php

namespace App\Core\Notification\Analytics\Forecast;

class TimeSeriesForecaster
{
    private array $models = [];
    private array $config;
    private array $metrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_model' => 'moving_average',
            'forecast_horizon' => 30,
            'confidence_interval' => 0.95
        ], $config);
    }

    public function forecast(array $data, string $model = null, array $options = []): array
    {
        $model = $model ?? $this->config['default_model'];
        if (!isset($this->models[$model])) {
            throw new \InvalidArgumentException("Unknown forecasting model: {$model}");
        }

        $preparedData = $this->prepareData($data);
        $forecast = $this->models[$model]->forecast($preparedData, $options);
        
        $this->updateMetrics($model, $forecast);
        
        return [
            'forecast' => $forecast['values'],
            'confidence_intervals' => $forecast['intervals'],
            'metrics' => $this->calculateForecastMetrics($data, $forecast['values'])
        ];
    }

    public function addModel(string $name, ForecastModel $model): void
    {
        $this->models[$name] = $model;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function prepareData(array $data): array
    {
        return array_map(function($point) {
            return [
                'timestamp' => strtotime($point['date']),
                'value' => (float)$point['value']
            ];
        }, $data);
    }

    private function updateMetrics(string $model, array $forecast): void
    {
        if (!isset($this->metrics[$model])) {
            $this->metrics[$model] = [
                'forecasts' => 0,
                'total_error' => 0,
                'accuracy' => []
            ];
        }

        $this->metrics[$model]['forecasts']++;
        $this->metrics[$model]['accuracy'][] = $forecast['accuracy'];
    }

    private function calculateForecastMetrics(array $actual, array $forecast): array
    {
        $errors = [];
        foreach ($actual as $i => $point) {
            if (isset($forecast[$i])) {
                $errors[] = abs($point['value'] - $forecast[$i]);
            }
        }

        return [
            'mae' => !empty($errors) ? array_sum($errors) / count($errors) : 0,
            'rmse' => !empty($errors) ? sqrt(array_sum(array_map(function($e) { return $e * $e; }, $errors)) / count($errors)) : 0,
            'accuracy' => !empty($errors) ? 1 - (array_sum($errors) / array_sum(array_column($actual, 'value'))) : 0
        ];
    }
}

interface ForecastModel
{
    public function forecast(array $data, array $options = []): array;
}

class MovingAverageModel implements ForecastModel
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'window_size' => 7,
            'min_periods' => 3
        ], $config);
    }

    public function forecast(array $data, array $options = []): array
    {
        $windowSize = $options['window_size'] ?? $this->config['window_size'];
        $values = array_column($data, 'value');
        
        $forecast = [];
        $intervals = [];
        
        for ($i = 0; $i < count($values); $i++) {
            $window = array_slice($values, max(0, $i - $windowSize + 1), min($windowSize, $i + 1));
            if (count($window) >= $this->config['min_periods']) {
                $forecast[$i] = array_sum($window) / count($window);
                $intervals[$i] = $this->calculateConfidenceInterval($window);
            }
        }

        return [
            'values' => $forecast,
            'intervals' => $intervals,
            'accuracy' => $this->calculateAccuracy($values, $forecast)
        ];
    }

    private function calculateConfidenceInterval(array $window): array
    {
        $mean = array_sum($window) / count($window);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $window)) / (count($window) - 1);
        
        $stdDev = sqrt($variance);
        $z = 1.96; // 95% confidence interval

        return [
            'lower' => $mean - $z * $stdDev,
            'upper' => $mean + $z * $stdDev
        ];
    }

    private function calculateAccuracy(array $actual, array $forecast): float
    {
        $errors = [];
        foreach ($actual as $i => $value) {
            if (isset($forecast[$i])) {
                $errors[] = abs($value - $forecast[$i]);
            }
        }

        return !empty($errors) ? 1 - (array_sum($errors) / array_sum($actual)) : 0;
    }
}

class ExponentialSmoothingModel implements ForecastModel
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'alpha' => 0.3,
            'beta' => 0.1,
            'seasonal_periods' => 7
        ], $config);
    }

    public function forecast(array $data, array $options = []): array
    {
        $values = array_column($data, 'value');
        $alpha = $options['alpha'] ?? $this->config['alpha'];
        
        $forecast = [];
        $intervals = [];
        $level = $values[0];
        
        for ($i = 0; $i < count($values); $i++) {
            if ($i > 0) {
                $level = $alpha * $values[$i] + (1 - $alpha) * $level;
            }
            $forecast[$i] = $level;
            $intervals[$i] = $this->calculateConfidenceInterval($values, $level, $i);
        }

        return [
            'values' => $forecast,
            'intervals' => $intervals,
            'accuracy' => $this->calculateAccuracy($values, $forecast)
        ];
    }

    private function calculateConfidenceInterval(array $values, float $level, int $index): array
    {
        $errors = [];
        for ($i = max(0, $index - 10); $i < $index; $i++) {
            if (isset($values[$i])) {
                $errors[] = abs($values[$i] - $level);
            }
        }

        $stdDev = !empty($errors) ? sqrt(array_sum(array_map(function($e) { return $e * $e; }, $errors)) / count($errors)) : 0;
        $z = 1.96;

        return [
            'lower' => $level - $z * $stdDev,
            'upper' => $level + $z * $stdDev
        ];
    }

    private function calculateAccuracy(array $actual, array $forecast): float
    {
        $errors = [];
        foreach ($actual as $i => $value) {
            if (isset($forecast[$i])) {
                $errors[] = abs($value - $forecast[$i]);
            }
        }

        return !empty($errors) ? 1 - (array_sum($errors) / array_sum($actual)) : 0;
    }
}
