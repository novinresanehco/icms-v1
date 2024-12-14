<?php

namespace App\Core\Audit\Calculators;

class StatisticsCalculator
{
    public function calculateMean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    public function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 == 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    public function calculateStandardDeviation(array $values): float
    {
        $mean = $this->calculateMean($values);
        $variance = array_reduce(
            $values,
            fn($carry, $item) => $carry + pow($item - $mean, 2),
            0
        ) / count($values);

        return sqrt($variance);
    }

    public function calculatePercentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $fraction = $index - floor($index);
        
        if ($fraction == 0) {
            return $values[(int)$index];
        }
        
        return $values[(int)$index] * (1 - $fraction) + $values[(int)$index + 1] * $fraction;
    }

    public function calculateCorrelation(array $x, array $y): float
    {
        $meanX = $this->calculateMean($x);
        $meanY = $this->calculateMean($y);
        $stdX = $this->calculateStandardDeviation($x);
        $stdY = $this->calculateStandardDeviation($y);
        
        $sum = 0;
        for ($i = 0; $i < count($x); $i++) {
            $sum += (($x[$i] - $meanX) / $stdX) * (($y[$i] - $meanY) / $stdY);
        }
        
        return $sum / (count($x) - 1);
    }
}

class MetricsCalculator
{
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addMetric(string $name, $value, array $tags = []): void
    {
        $key = $this->generateKey($name, $tags);
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [];
        }
        $this->metrics[$key][] = $value;
    }

    public function calculateSummary(string $metric): array
    {
        $values = $this->metrics[$metric] ?? [];
        if (empty($values)) {
            return [];
        }

        $stats = new StatisticsCalculator();
        return [
            'count' => count($values),
            'mean' => $stats->calculateMean($values),
            'median' => $stats->calculateMedian($values),
            'stddev' => $stats->calculateStandardDeviation($values),
            'p95' => $stats->calculatePercentile($values, 95),
            'p99' => $stats->calculatePercentile($values, 99)
        ];
    }

    private function generateKey(string $name, array $tags): string
    {
        ksort($tags);
        return $name . ':' . http_build_query($tags);
    }
}

class AnomalyCalculator
{
    private StatisticsCalculator $stats;
    private array $config;

    public function __construct(StatisticsCalculator $stats, array $config = [])
    {
        $this->stats = $stats;
        $this->config = $config;
    }

    public function detectAnomalies(array $values, float $threshold = 3.0): array
    {
        $mean = $this->stats->calculateMean($values);
        $stdDev = $this->stats->calculateStandardDeviation($values);
        
        $anomalies = [];
        foreach ($values as $index => $value) {
            $zScore = abs(($value - $mean) / $stdDev);
            if ($zScore > $threshold) {
                $anomalies[] = [
                    'index' => $index,
                    'value' => $value,
                    'zscore' => $zScore
                ];
            }
        }
        
        return $anomalies;
    }

    public function calculateConfidenceInterval(array $values, float $confidence = 0.95): array
    {
        $mean = $this->stats->calculateMean($values);
        $stdError = $this->stats->calculateStandardDeviation($values) / sqrt(count($values));
        $tScore = $this->getTScore($confidence, count($values) - 1);
        
        $margin = $tScore * $stdError;
        
        return [
            'lower' => $mean - $margin,
            'upper' => $mean + $margin
        ];
    }

    private function getTScore(float $confidence, int $degreesOfFreedom): float
    {
        // Using a simplified t-score lookup for common confidence levels
        return match($confidence) {
            0.95 => 1.96,
            0.99 => 2.58,
            0.999 => 3.29,
            default => 1.96
        };
    }
}

class TrendCalculator
{
    private StatisticsCalculator $stats;
    private array $config;

    public function __construct(StatisticsCalculator $stats, array $config = [])
    {
        $this->stats = $stats;
        $this->config = $config;
    }

    public function calculateTrend(array $values): array
    {
        $n = count($values);
        $x = range(0, $n - 1);
        
        $meanX = $this->stats->calculateMean($x);
        $meanY = $this->stats->calculateMean($values);
        
        $numerator = 0;
        $denominator = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $xDiff = $x[$i] - $meanX;
            $numerator += $xDiff * ($values[$i] - $meanY);
            $denominator += $xDiff * $xDiff;
        }
        
        $slope = $denominator != 0 ? $numerator / $denominator : 0;
        $intercept = $meanY - $slope * $meanX;
        
        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'trend_line' => array_map(
                fn($x) => $slope * $x + $intercept,
                $x
            )
        ];
    }

    public function detectSeasonality(array $values, int $period): array
    {
        $seasons = array_chunk($values, $period);
        $seasonalIndices = [];
        
        foreach ($seasons as $season) {
            $seasonMean = $this->stats->calculateMean($season);
            $seasonalIndices[] = array_map(
                fn($value) => $value / $seasonMean,
                $season
            );
        }
        
        return array_map(
            fn($i) => $this->stats->calculateMean(array_column($seasonalIndices, $i)),
            range(0, $period - 1)
        );
    }
}
