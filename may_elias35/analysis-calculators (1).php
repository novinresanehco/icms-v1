<?php

namespace App\Core\Audit\Calculators;

class StatisticalCalculator
{
    private array $data;
    private array $config;

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

    public function calculateMode(array $values): array
    {
        $frequencies = array_count_values($values);
        $maxFrequency = max($frequencies);
        
        return array_keys(array_filter($frequencies, fn($freq) => $freq === $maxFrequency));
    }

    public function calculateStandardDeviation(array $values): float
    {
        $mean = $this->calculateMean($values);
        $squares = array_map(fn($x) => pow($x - $mean, 2), $values);
        
        return sqrt($this->calculateMean($squares));
    }

    public function calculateVariance(array $values): float
    {
        return pow($this->calculateStandardDeviation($values), 2);
    }

    public function calculateSkewness(array $values): float
    {
        $mean = $this->calculateMean($values);
        $std = $this->calculateStandardDeviation($values);
        $n = count($values);
        
        $cubed = array_map(fn($x) => pow($x - $mean, 3), $values);
        
        return (array_sum($cubed) / $n) / pow($std, 3);
    }

    public function calculateKurtosis(array $values): float
    {
        $mean = $this->calculateMean($values);
        $std = $this->calculateStandardDeviation($values);
        $n = count($values);
        
        $fourth = array_map(fn($x) => pow($x - $mean, 4), $values);
        
        return (array_sum($fourth) / $n) / pow($std, 4) - 3;
    }

    public function calculatePearsonCorrelation(array $x, array $y): float
    {
        $meanX = $this->calculateMean($x);
        $meanY = $this->calculateMean($y);
        $stdX = $this->calculateStandardDeviation($x);
        $stdY = $this->calculateStandardDeviation($y);
        
        $covariance = 0;
        $n = count($x);
        
        for ($i = 0; $i < $n; $i++) {
            $covariance += ($x[$i] - $meanX) * ($y[$i] - $meanY);
        }
        
        $covariance /= $n;
        
        return $covariance / ($stdX * $stdY);
    }

    public function calculateConfidenceInterval(array $values, float $confidence = 0.95): array
    {
        $mean = $this->calculateMean($values);
        $std = $this->calculateStandardDeviation($values);
        $n = count($values);
        
        $z = $this->getZScore($confidence);
        $margin = $z * ($std / sqrt($n));
        
        return [
            'lower' => $mean - $margin,
            'upper' => $mean + $margin
        ];
    }

    public function performHypothesisTest(array $sample1, array $sample2, string $type = 'ttest'): array
    {
        switch ($type) {
            case 'ttest':
                return $this->performTTest($sample1, $sample2);
            case 'anova':
                return $this->performANOVA($sample1, $sample2);
            case 'chisquare':
                return $this->performChiSquare($sample1, $sample2);
            default:
                throw new \InvalidArgumentException("Unknown test type: {$type}");
        }
    }

    private function performTTest(array $sample1, array $sample2): array
    {
        $mean1 = $this->calculateMean($sample1);
        $mean2 = $this->calculateMean($sample2);
        $var1 = $this->calculateVariance($sample1);
        $var2 = $this->calculateVariance($sample2);
        $n1 = count($sample1);
        $n2 = count($sample2);
        
        $pooledVar = (($n1 - 1) * $var1 + ($n2 - 1) * $var2) / ($n1 + $n2 - 2);
        $se = sqrt($pooledVar * (1/$n1 + 1/$n2));
        $t = ($mean1 - $mean2) / $se;
        $df = $n1 + $n2 - 2;
        
        return [
            't_statistic' => $t,
            'degrees_of_freedom' => $df,
            'p_value' => $this->calculatePValue($t, $df)
        ];
    }

    private function getZScore(float $confidence): float
    {
        $alpha = 1 - $confidence;
        return abs(Statistics::normalInv($alpha/2));
    }
}

class TrendCalculator
{
    public function calculateMovingAverage(array $values, int $window): array
    {
        $result = [];
        $count = count($values);
        
        for ($i = 0; $i <= $count - $window; $i++) {
            $slice = array_slice($values, $i, $window);
            $result[] = array_sum($slice) / $window;
        }
        
        return $result;
    }

    public function calculateExponentialSmoothing(array $values, float $alpha): array
    {
        $result = [$values[0]];
        $lastSmoothed = $values[0];
        
        for ($i = 1; $i < count($values); $i++) {
            $smoothed = $alpha * $values[$i] + (1 - $alpha) * $lastSmoothed;
            $result[] = $smoothed;
            $lastSmoothed = $smoothed;
        }
        
        return $result;
    }

    public function detectSeasonality(array $values, int $period): array
    {
        $seasons = [];
        $count = count($values);
        
        for ($i = 0; $i < $period; $i++) {
            $seasonValues = [];
            for ($j = $i; $j < $count; $j += $period) {
                if (isset($values[$j])) {
                    $seasonValues[] = $values[$j];
                }
            }
            $seasons[] = array_sum($seasonValues) / count($seasonValues);
        }
        
        return $seasons;
    }

    public function calculateTrend(array $values): array
    {
        $n = count($values);
        $x = range(0, $n - 1);
        
        $sumX = array_sum($x);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $values[$i];
            $sumX2 += $x[$i] * $x[$i];
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'trend_line' => array_map(fn($xi) => $slope * $xi + $intercept, $x)
        ];
    }

    public function forecastValues(array $values, int $horizon, array $config): array
    {
        $trend = $this->calculateTrend($values);
        $seasons = $this->detectSeasonality($values, $config['seasonal_period']);
        $n = count($values);
        
        $forecasts = [];
        for ($i = 0; $i < $horizon; $i++) {
            $trendComponent = $trend['slope'] * ($n + $i) + $trend['intercept'];
            $seasonalComponent = $seasons[($n + $i) % count($seasons)];
            $forecasts[] = $trendComponent + $seasonalComponent;
        }
        
        return $forecasts;
    }
}

class AnomalyCalculator
{
    public function detectOutliers(array $values, string $method = 'zscore'): array
    {
        switch ($method) {
            case 'zscore':
                return $this->detectZScoreOutliers($values);
            case 'iqr':
                return $this->detectIQROutliers($values);
            case 'mad':
                return $this->detectMADOutliers($values);
            default:
                throw new \InvalidArgumentException("Unknown outlier detection method: {$method}");
        }
    }

    private function detectZScoreOutliers(array $values, float $threshold = 3.0): array
    {
        $mean = array_sum($values) / count($values);
        $std = sqrt(array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values));
        
        $outliers = [];
        foreach ($values as $index => $value) {
            $zscore = abs(($value - $mean) / $std);
            if ($zscore > $threshold) {
                $outliers[] = [
                    'index' => $index,
                    'value' => $value,
                    'zscore' => $zscore
                ];
            }
        }
        
        return $outliers;
    }

    private function detectIQROutliers(array $values, float $multiplier = 1.5): array
    {
        sort($values);
        $count = count($values);
        $q1Index = floor($count / 4);
        $q3Index = floor(3 * $count / 4);
        
        $q1 = $values[$q1Index];
        $q3 = $values[$q3Index];
        $iqr = $q3 - $q1;
        
        $lowerBound = $q1 - $multiplier * $iqr;
        $upperBound = $q3 + $multiplier * $iqr;
        
        $outliers = [];
        foreach ($values as $index => $value) {
            if ($value < $lowerBound || $value > $upperBound) {
                $outliers[] = [
                    'index' => $index,
                    'value' => $value,
                    'bound_violated' => $value < $lowerBound ? 'lower' : 'upper'
                ];
            }
        }
        
        return $outliers;
    }

    public function detectPatternAnomalies(array $values, array $pattern): array
    {
        $anomalies = [];
        $patternLength = count($pattern);
        
        for ($i = 0; $i < count($values) - $patternLength + 1; $i++) {
            $sequence = array_slice($values, $i, $patternLength);
            $deviation = $this->calculatePatternDeviation($sequence, $pattern);
            
            if ($deviation > $this->config['pattern_threshold']) {
                $anomalies[] = [
                    'index' => $i,
                    'sequence' => $sequence,
                    'deviation' => $deviation
                ];
            }
        }
        
        return $anomalies;
    }

    private function calculatePatternDeviation(array $sequence, array $pattern): float
    {
        $deviation = 0;
        for ($i = 0; $i < count($sequence); $i++) {
            $deviation += abs($sequence[$i] - $pattern[$i]);
        }
        return $deviation / count($sequence);
    }
}
