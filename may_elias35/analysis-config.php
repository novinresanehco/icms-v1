<?php

namespace App\Core\Audit\Config;

class AnalysisConfig
{
    private array $statisticalConfig;
    private array $patternConfig;
    private array $trendConfig;
    private array $anomalyConfig;
    private array $processingConfig;

    public function __construct(array $config)
    {
        $this->statisticalConfig = $config['statistical'] ?? [];
        $this->patternConfig = $config['pattern'] ?? [];
        $this->trendConfig = $config['trend'] ?? [];
        $this->anomalyConfig = $config['anomaly'] ?? [];
        $this->processingConfig = $config['processing'] ?? [];
    }

    public function getStatisticalConfig(): StatisticalConfig
    {
        return new StatisticalConfig($this->statisticalConfig);
    }

    public function getPatternConfig(): PatternConfig
    {
        return new PatternConfig($this->patternConfig);
    }

    public function getTrendConfig(): TrendConfig
    {
        return new TrendConfig($this->trendConfig);
    }

    public function getAnomalyConfig(): AnomalyConfig
    {
        return new AnomalyConfig($this->anomalyConfig);
    }

    public function getProcessingConfig(): ProcessingConfig
    {
        return new ProcessingConfig($this->processingConfig);
    }
}

class StatisticalConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConfidenceLevel(): float
    {
        return $this->config['confidence_level'] ?? 0.95;
    }

    public function getSignificanceLevel(): float
    {
        return $this->config['significance_level'] ?? 0.05;
    }

    public function getDistributionTypes(): array
    {
        return $this->config['distribution_types'] ?? ['normal', 'poisson', 'binomial'];
    }

    public function getCorrelationMethods(): array
    {
        return $this->config['correlation_methods'] ?? ['pearson', 'spearman'];
    }
}

class PatternConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getMinSupport(): float
    {
        return $this->config['min_support'] ?? 0.1;
    }

    public function getMaxGap(): int
    {
        return $this->config['max_gap'] ?? 5;
    }

    public function getMinConfidence(): float
    {
        return $this->config['min_confidence'] ?? 0.5;
    }

    public function getPatternTypes(): array
    {
        return $this->config['pattern_types'] ?? ['sequential', 'temporal', 'behavioral'];
    }
}

class TrendConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getSeasonalityConfig(): array
    {
        return $this->config['seasonality'] ?? [
            'period' => 12,
            'min_strength' => 0.3
        ];
    }

    public function getCycleConfig(): array
    {
        return $this->config['cycle'] ?? [
            'min_length' => 2,
            'max_length' => 52
        ];
    }

    public function getForecastConfig(): array
    {
        return $this->config['forecast'] ?? [
            'horizon' => 12,
            'confidence_level' => 0.95
        ];
    }
}

class AnomalyConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getOutlierConfig(): array
    {
        return $this->config['outlier'] ?? [
            'method' => 'zscore',
            'threshold' => 3
        ];
    }

    public function getContextConfig(): array
    {
        return $this->config['context'] ?? [
            'window_size' => 30,
            'sensitivity' => 0.8
        ];
    }

    public function getAnomalyTypes(): array
    {
        return $this->config['types'] ?? ['statistical', 'pattern', 'contextual'];
    }
}

class ProcessingConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getCleaningConfig(): array
    {
        return $this->config['cleaning'] ?? [
            'remove_duplicates' => true,
            'handle_missing' => 'mean'
        ];
    }

    public function getNormalizationConfig(): array
    {
        return $this->config['normalization'] ?? [
            'method' => 'zscore',
            'scale_range' => [-1, 1]
        ];
    }

    public function getTransformationConfig(): array
    {
        return $this->config['transformation'] ?? [
            'encode_categories' => true,
            'generate_features' => true
        ];
    }
}
