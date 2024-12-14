```php
namespace App\Core\Media\Analytics\Anomaly;

class AnomalyDetectionEngine
{
    protected StatisticalAnalyzer $statisticalAnalyzer;
    protected MachineLearningDetector $mlDetector;
    protected TimeSeriesAnalyzer $timeSeriesAnalyzer;
    protected MetricsRepository $metricsRepo;
    protected AnomalyScorer $scorer;

    public function __construct(
        StatisticalAnalyzer $statisticalAnalyzer,
        MachineLearningDetector $mlDetector,
        TimeSeriesAnalyzer $timeSeriesAnalyzer,
        MetricsRepository $metricsRepo,
        AnomalyScorer $scorer
    ) {
        $this->statisticalAnalyzer = $statisticalAnalyzer;
        $this->mlDetector = $mlDetector;
        $this->timeSeriesAnalyzer = $timeSeriesAnalyzer;
        $this->metricsRepo = $metricsRepo;
        $this->scorer = $scorer;
    }

    public function detectAnomalies(array $metrics, array $config = []): AnomalyReport
    {
        // Initialize detection context
        $context = $this->buildContext($metrics);

        // Run statistical analysis
        $statisticalAnomalies = $this->statisticalAnalyzer->analyze($metrics, $context);

        // Run ML-based detection
        $mlAnomalies = $this->mlDetector->detect($metrics, $context);

        // Analyze time series
        $timeSeriesAnomalies = $this->timeSeriesAnalyzer->findAnomalies($metrics, $context);

        // Combine and score results
        $combinedAnomalies = $this->combineResults([
            $statisticalAnomalies,
            $mlAnomalies,
            $timeSeriesAnomalies
        ]);

        return new AnomalyReport([
            'anomalies' => $combinedAnomalies,
            'context' => $context,
            'metrics' => $metrics,
            'recommendations' => $this->generateRecommendations($combinedAnomalies)
        ]);
    }

    protected function buildContext(array $metrics): DetectionContext
    {
        return new DetectionContext([
            'time_range' => $this->calculateTimeRange($metrics),
            'baseline' => $this->calculateBaseline($metrics),
            'thresholds' => $this->calculateThresholds($metrics),
            'seasonality' => $this->detectSeasonality($metrics)
        ]);
    }
}

class StatisticalAnalyzer
{
    protected float $zScoreThreshold = 3.0;
    protected int $minDataPoints = 30;

    public function analyze(array $metrics, DetectionContext $context): array
    {
        $anomalies = [];

        foreach ($metrics as $metric => $values) {
            // Calculate basic statistics
            $stats = $this->calculateStatistics($values);

            // Detect outliers using Z-score
            $outliers = $this->detectOutliers($values, $stats);

            // Detect trend changes
            $trendChanges = $this->detectTrendChanges($values, $context);

            // Detect variance changes
            $varianceChanges = $this->detectVarianceChanges($values, $stats);

            $anomalies[$metric] = array_merge(
                $outliers,
                $trendChanges,
                $varianceChanges
            );
        }

        return $anomalies;
    }

    protected function detectOutliers(array $values, array $stats): array
    {
        $outliers = [];
        
        foreach ($values as $timestamp => $value) {
            $zScore = ($value - $stats['mean']) / $stats['std_dev'];
            
            if (abs($zScore) > $this->zScoreThreshold) {
                $outliers[] = new Anomaly([
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'type' => 'outlier',
                    'score' => abs($zScore),
                    'metadata' => [
                        'z_score' => $zScore,
                        'threshold' => $this->zScoreThreshold
                    ]
                ]);
            }
        }

        return $outliers;
    }

    protected function detectTrendChanges(array $values, DetectionContext $context): array
    {
        $changes = [];
        $windowSize = (int) (count($values) * 0.1); // 10% of data points
        
        for ($i = $windowSize; $i < count($values) - $windowSize; $i++) {
            $before = array_slice($values, $i - $windowSize, $windowSize);
            $after = array_slice($values, $i, $windowSize);
            
            $beforeTrend = $this->calculateTrend($before);
            $afterTrend = $this->calculateTrend($after);
            
            if (abs($afterTrend - $beforeTrend) > $context->trendChangeThreshold) {
                $changes[] = new Anomaly([
                    'timestamp' => array_keys($values)[$i],
                    'type' => 'trend_change',
                    'score' => abs($afterTrend - $beforeTrend),
                    'metadata' => [
                        'before_trend' => $beforeTrend,
                        'after_trend' => $afterTrend
                    ]
                ]);
            }
        }

        return $changes;
    }
}

class MachineLearningDetector
{
    protected IsolationForest $isolationForest;
    protected AutoEncoder $autoEncoder;
    protected array $models = [];

    public function detect(array $metrics, DetectionContext $context): array
    {
        $anomalies = [];

        // Prepare data for ML
        $preparedData = $this->prepareData($metrics);

        // Run Isolation Forest detection
        $ifAnomalies = $this->isolationForest->detect($preparedData);

        // Run AutoEncoder detection
        $aeAnomalies = $this->autoEncoder->detect($preparedData);

        // Ensemble results
        $anomalies = $this->ensembleResults([
            'isolation_forest' => $ifAnomalies,
            'autoencoder' => $aeAnomalies
        ]);

        return $this->postProcess($anomalies, $context);
    }

    protected function prepareData(array $metrics): array
    {
        $prepared = [];

        foreach ($metrics as $metric => $values) {
            // Normalize values
            $normalized = $this->normalize($values);
            
            // Extract features
            $features = $this->extractFeatures($normalized);
            
            // Create windows
            $windows = $this->createWindows($features);
            
            $prepared[$metric] = [
                'features' => $features,
                'windows' => $windows,
                'normalized' => $normalized
            ];
        }

        return $prepared;
    }

    protected function ensembleResults(array $detectionResults): array
    {
        $ensembled = [];
        $weights = [
            'isolation_forest' => 0.6,
            'autoencoder' => 0.4
        ];

        foreach ($detectionResults as $method => $results) {
            foreach ($results as $anomaly) {
                $key = $anomaly['timestamp'];
                if (!isset($ensembled[$key])) {
                    $ensembled[$key] = [
                        'score' => 0,
                        'detections' => []
                    ];
                }
                
                $ensembled[$key]['score'] += $anomaly['score'] * $weights[$method];
                $ensembled[$key]['detections'][] = [
                    'method' => $method,
                    'score' => $anomaly['score']
                ];
            }
        }

        return $ensembled;
    }
}

class TimeSeriesAnalyzer
{
    protected SeasonalDecomposer $decomposer;
    protected TrendAnalyzer $trendAnalyzer;
    protected ChangePointDetector $changePointDetector;

    public function findAnomalies(array $metrics, DetectionContext $context): array
    {
        $anomalies = [];

        foreach ($metrics as $metric => $values) {
            // Decompose time series
            $components = $this->decomposer->decompose($values);
            
            // Detect seasonal anomalies
            $seasonalAnomalies = $this->detectSeasonalAnomalies(
                $components['seasonal'],
                $context
            );
            
            // Detect trend anomalies
            $trendAnomalies = $this->trendAnalyzer->detectAnomalies(
                $components['trend']
            );
            
            // Detect change points
            $changePoints = $this->changePointDetector->detect($values);
            
            $anomalies[$metric] = array_merge(
                $seasonalAnomalies,
                $trendAnomalies,
                $changePoints
            );
        }

        return $anomalies;
    }

    protected function detectSeasonalAnomalies(array $seasonal, DetectionContext $context): array
    {
        $anomalies = [];
        $pattern = $context->seasonality['pattern'];
        
        foreach ($seasonal as $timestamp => $value) {
            $expected = $this->getExpectedSeasonalValue($timestamp, $pattern);
            $deviation = abs($value - $expected);
            
            if ($deviation > $context->seasonality['threshold']) {
                $anomalies[] = new Anomaly([
                    'timestamp' => $timestamp,
                    'type' => 'seasonal_anomaly',
                    'score' => $deviation,
                    'metadata' => [
                        'expected' => $expected,
                        'actual' => $value
                    ]
                ]);
            }
        }

        return $anomalies;
    }
}

class AnomalyReport
{
    public array $anomalies;
    public DetectionContext $context;
    public array $metrics;
    public array $recommendations;

    public function __construct(array $data)
    {
        $this->anomalies = $data['anomalies'];
        $this->context = $data['context'];
        $this->metrics = $data['metrics'];
        $this->recommendations = $data['recommendations'];
    }

    public function getSeverityLevel(): string
    {
        $maxScore = max(array_column($this->anomalies, 'score'));
        
        if ($maxScore > 0.8) return 'critical';
        if ($maxScore > 0.5) return 'warning';
        return 'info';
    }

    public function getAnomalyCount(): int
    {
        return count($this->anomalies);
    }

    public function getTopAnomalies(int $limit = 5): array
    {
        $sorted = $this->anomalies;
        usort($sorted, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($sorted, 0, $limit);
    }
}
```
