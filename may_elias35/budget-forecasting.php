```php
namespace App\Core\Media\Analytics\Forecasting;

class BudgetForecastingEngine
{
    protected TimeSeriesAnalyzer $timeSeriesAnalyzer;
    protected MachineLearningPredictor $mlPredictor;
    protected SeasonalityDetector $seasonalityDetector;
    protected TrendAnalyzer $trendAnalyzer;

    public function __construct(
        TimeSeriesAnalyzer $timeSeriesAnalyzer,
        MachineLearningPredictor $mlPredictor,
        SeasonalityDetector $seasonalityDetector,
        TrendAnalyzer $trendAnalyzer
    ) {
        $this->timeSeriesAnalyzer = $timeSeriesAnalyzer;
        $this->mlPredictor = $mlPredictor;
        $this->seasonalityDetector = $seasonalityDetector;
        $this->trendAnalyzer = $trendAnalyzer;
    }

    public function generateForecast(string $timeframe = '6 months'): ForecastReport
    {
        // Get historical data
        $historicalData = $this->getHistoricalData();

        // Detect patterns
        $patterns = $this->detectPatterns($historicalData);

        // Generate predictions
        $predictions = $this->generatePredictions($patterns, $timeframe);

        // Create confidence intervals
        $intervals = $this->createConfidenceIntervals($predictions);

        return new ForecastReport([
            'predictions' => $predictions,
            'confidence_intervals' => $intervals,
            'patterns' => $patterns,
            'risk_factors' => $this->analyzeRiskFactors($predictions)
        ]);
    }

    protected function detectPatterns(array $historicalData): array
    {
        // Detect seasonality
        $seasonality = $this->seasonalityDetector->detect($historicalData);

        // Analyze trends
        $trends = $this->trendAnalyzer->analyze($historicalData);

        // Identify cycles
        $cycles = $this->identifyCycles($historicalData);

        return [
            'seasonality' => $seasonality,
            'trends' => $trends,
            'cycles' => $cycles,
            'anomalies' => $this->detectAnomalies($historicalData)
        ];
    }
}

class MachineLearningPredictor
{
    protected array $models;
    protected ModelSelector $modelSelector;
    protected DataPreprocessor $preprocessor;

    public function predict(array $data, string $timeframe): array
    {
        // Preprocess data
        $processedData = $this->preprocessor->process($data);

        // Select best model
        $selectedModel = $this->modelSelector->selectBestModel($processedData);

        // Generate predictions
        $predictions = $selectedModel->predict($processedData, $timeframe);

        // Calculate uncertainty
        $uncertainty = $this->calculateUncertainty($predictions, $selectedModel);

        return [
            'predictions' => $predictions,
            'model_info' => [
                'name' => get_class($selectedModel),
                'accuracy' => $selectedModel->getAccuracy(),
                'confidence' => $selectedModel->getConfidence()
            ],
            'uncertainty' => $uncertainty
        ];
    }

    protected function calculateUncertainty(array $predictions, PredictiveModel $model): array
    {
        return [
            'prediction_intervals' => $model->getPredictionIntervals($predictions),
            'confidence_scores' => $model->getConfidenceScores($predictions),
            'volatility_metrics' => $this->calculateVolatilityMetrics($predictions)
        ];
    }
}

class SeasonalityDetector
{
    protected FourierAnalyzer $fourierAnalyzer;
    protected PatternMatcher $patternMatcher;
    protected array $config;

    public function detect(array $data): array
    {
        // Perform Fourier analysis
        $fourierResults = $this->fourierAnalyzer->analyze($data);

        // Detect periodic patterns
        $periodicPatterns = $this->patternMatcher->findPeriodicPatterns($data);

        // Identify seasonal components
        $seasonalComponents = $this->identifySeasonalComponents($data, $fourierResults);

        return [
            'components' => $seasonalComponents,
            'strength' => $this->calculateSeasonalStrength($seasonalComponents),
            'periods' => $this->identifySignificantPeriods($fourierResults),
            'patterns' => $periodicPatterns
        ];
    }

    protected function identifySeasonalComponents(array $data, array $fourierResults): array
    {
        $components = [];
        
        foreach ($fourierResults['frequencies'] as $frequency) {
            if ($this->isSignificantFrequency($frequency)) {
                $components[] = [
                    'frequency' => $frequency['value'],
                    'amplitude' => $frequency['amplitude'],
                    'phase' => $frequency['phase'],
                    'significance' => $this->calculateSignificance($frequency)
                ];
            }
        }

        return $components;
    }
}

class TrendAnalyzer
{
    protected RegressionAnalyzer $regressionAnalyzer;
    protected ChangePointDetector $changePointDetector;
    protected TrendDecomposer $decomposer;

    public function analyze(array $data): array
    {
        // Perform regression analysis
        $regression = $this->regressionAnalyzer->analyze($data);

        // Detect change points
        $changePoints = $this->changePointDetector->detect($data);

        // Decompose trend components
        $components = $this->decomposer->decompose($data);

        return [
            'overall_trend' => $regression['trend'],
            'change_points' => $changePoints,
            'components' => $components,
            'strength' => $this->calculateTrendStrength($components),
            'confidence' => $this->calculateConfidence($regression)
        ];
    }

    protected function calculateTrendStrength(array $components): float
    {
        $trendVariance = $this->calculateVariance($components['trend']);
        $totalVariance = $this->calculateTotalVariance($components);
        
        return $trendVariance / $totalVariance;
    }
}

class ForecastReport
{
    protected array $predictions;
    protected array $confidenceIntervals;
    protected array $patterns;
    protected array $riskFactors;

    public function getPredictedCosts(string $timeframe): array
    {
        return array_filter(
            $this->predictions,
            fn($p) => $p['timestamp'] <= strtotime($timeframe)
        );
    }

    public function getConfidenceRange(string $timeframe): array
    {
        $intervals = $this->confidenceIntervals;
        return [
            'lower' => min(array_column($intervals, 'lower')),
            'upper' => max(array_column($intervals, 'upper')),
            'mean' => array_sum(array_column($intervals, 'mean')) / count($intervals)
        ];
    }

    public function getRiskAssessment(): array
    {
        return [
            'overall_risk' => $this->calculateOverallRisk(),
            'factors' => $this->riskFactors,
            'confidence_level' => $this->calculateConfidenceLevel(),
            'volatility' => $this->calculateVolatility()
        ];
    }

    protected function calculateOverallRisk(): float
    {
        $weights = [
            'prediction_uncertainty' => 0.4,
            'pattern_volatility' => 0.3,
            'historical_accuracy' => 0.3
        ];

        $riskScores = [
            'prediction_uncertainty' => $this->calculateUncertaintyScore(),
            'pattern_volatility' => $this->calculateVolatilityScore(),
            'historical_accuracy' => $this->calculateAccuracyScore()
        ];

        return array_sum(array_map(
            fn($key, $weight) => $riskScores[$key] * $weight,
            array_keys($weights),
            $weights
        ));
    }
}
```
