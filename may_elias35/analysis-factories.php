<?php

namespace App\Core\Audit\Factories;

class AnalysisEngineFactory
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function createDataProcessor(array $config = []): DataProcessorInterface
    {
        return new DataProcessor(
            $this->container->get(DataCleaner::class),
            $this->container->get(DataNormalizer::class),
            $this->container->get(DataTransformer::class),
            $this->container->get(DataValidator::class),
            new ProcessingConfig($config)
        );
    }

    public function createStatisticalAnalyzer(array $config = []): StatisticalAnalyzerInterface
    {
        return new StatisticalAnalyzer(
            $this->container->get(StatisticalCalculator::class),
            new StatisticalConfig($config)
        );
    }

    public function createPatternDetector(array $config = []): PatternDetectorInterface
    {
        return new PatternDetector(
            $this->container->get(PatternMatcher::class),
            $this->container->get(PatternAnalyzer::class),
            new PatternConfig($config)
        );
    }

    public function createTrendAnalyzer(array $config = []): TrendAnalyzerInterface
    {
        return new TrendAnalyzer(
            $this->container->get(TrendCalculator::class),
            $this->container->get(ForecastEngine::class),
            new TrendConfig($config)
        );
    }

    public function createAnomalyDetector(array $config = []): AnomalyDetectorInterface
    {
        return new AnomalyDetector(
            $this->container->get(AnomalyCalculator::class),
            $this->container->get(AnomalyClassifier::class),
            new AnomalyConfig($config)
        );
    }

    public function createAnalysisEngine(array $config = []): AnalysisEngine
    {
        return new AnalysisEngine(
            $this->createDataProcessor($config['processing'] ?? []),
            $this->createStatisticalAnalyzer($config['statistical'] ?? []),
            $this->createPatternDetector($config['pattern'] ?? []),
            $this->createTrendAnalyzer($config['trend'] ?? []),
            $this->createAnomalyDetector($config['anomaly'] ?? []),
            $this->container->get(CacheManager::class)
        );
    }
}

class ConfigFactory
{
    public function createFromArray(array $config): ConfigInterface
    {
        return new AnalysisConfig([
            'statistical' => $this->createStatisticalConfig($config['statistical'] ?? []),
            'pattern' => $this->createPatternConfig($config['pattern'] ?? []),
            'trend' => $this->createTrendConfig($config['trend'] ?? []),
            'anomaly' => $this->createAnomalyConfig($config['anomaly'] ?? []),
            'processing' => $this->createProcessingConfig($config['processing'] ?? [])
        ]);
    }

    protected function createStatisticalConfig(array $config): StatisticalConfigInterface
    {
        return new StatisticalConfig([
            'confidence_level' => $config['confidence_level'] ?? 0.95,
            'significance_level' => $config['significance_level'] ?? 0.05,
            'distribution_types' => $config['distribution_types'] ?? ['normal', 'poisson'],
            'correlation_methods' => $config['correlation_methods'] ?? ['pearson', 'spearman']
        ]);
    }

    protected function createPatternConfig(array $config): PatternConfigInterface
    {
        return new PatternConfig([
            'min_support' => $config['min_support'] ?? 0.1,
            'max_gap' => $config['max_gap'] ?? 5,
            'min_confidence' => $config['min_confidence'] ?? 0.5,
            'pattern_types' => $config['pattern_types'] ?? ['sequential', 'temporal']
        ]);
    }

    protected function createTrendConfig(array $config): TrendConfigInterface
    {
        return new TrendConfig([
            'seasonality' => $config['seasonality'] ?? [
                'period' => 12,
                'min_strength' => 0.3
            ],
            'cycle' => $config['cycle'] ?? [
                'min_length' => 2,
                'max_length' => 52
            ],
            'forecast' => $config['forecast'] ?? [
                'horizon' => 12,
                'confidence_level' => 0.95
            ]
        ]);
    }

    protected function createAnomalyConfig(array $config): AnomalyConfigInterface
    {
        return new AnomalyConfig([
            'outlier' => $config['outlier'] ?? [
                'method' => 'zscore',
                'threshold' => 3
            ],
            'context' => $config['context'] ?? [
                'window_size' => 30,
                'sensitivity' => 0.8
            ],
            'types' => $config['types'] ?? ['statistical', 'contextual']
        ]);
    }

    protected function createProcessingConfig(array $config): ProcessingConfigInterface
    {
        return new ProcessingConfig([
            'cleaning' => $config['cleaning'] ?? [
                'remove_duplicates' => true,
                'handle_missing' => 'mean'
            ],
            'normalization' => $config['normalization'] ?? [
                'method' => 'zscore',
                'scale_range' => [-1, 1]
            ],
            'transformation' => $config['transformation'] ?? [
                'encode_categories' => true,
                'generate_features' => true
            ]
        ]);
    }
}

class ValidatorFactory
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function createDataValidator(array $config = []): ValidatorInterface
    {
        return new DataValidator(
            $this->container->get(ValidationRuleEngine::class),
            new ValidationConfig($config)
        );
    }

    public function createConfigValidator(): ValidatorInterface
    {
        return new ConfigValidator(
            $this->container->get(ConfigurationSchema::class)
        );
    }

    public function createAnalysisValidator(): ValidatorInterface
    {
        return new AnalysisValidator([
            'config' => $this->createConfigValidator(),
            'data' => $this->createDataValidator(),
            'parameters' => new ParameterValidator(),
            'constraints' => new ConstraintValidator()
        ]);
    }
}
