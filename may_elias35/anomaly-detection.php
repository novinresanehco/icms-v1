<?php

namespace App\Core\Metrics;

use App\Core\Interfaces\AnomalyDetectionInterface;
use App\Core\Exceptions\{SecurityException, AnomalyException};
use Illuminate\Support\Facades\{DB, Log, Cache};

class AnomalyDetector implements AnomalyDetectionInterface
{
    private SecurityManager $security;
    private MetricsAnalyzer $analyzer;
    private array $thresholds;
    private array $patterns;

    public function __construct(
        SecurityManager $security,
        MetricsAnalyzer $analyzer,
        array $config
    ) {
        $this->security = $security;
        $this->analyzer = $analyzer;
        $this->thresholds = $config['anomaly_thresholds'];
        $this->patterns = $config['anomaly_patterns'];
    }

    public function analyze(array $metrics): AnomalyReport
    {
        $analysisId = $this->generateAnalysisId();

        try {
            DB::beginTransaction();

            // Check for statistical anomalies
            $statisticalAnomalies = $this->detectStatisticalAnomalies($metrics);

            // Check for pattern-based anomalies
            $patternAnomalies = $this->detectPatternAnomalies($metrics);

            // Check for security anomalies
            $securityAnomalies = $this->detectSecurityAnomalies($metrics);

            // Generate comprehensive analysis
            $analysis = $this->generateAnalysis(
                $metrics,
                $statisticalAnomalies,
                $patternAnomalies,
                $securityAnomalies
            );

            // Store analysis results
            $this->storeAnalysis($analysisId, $analysis);

            // Handle any critical anomalies
            if ($this->hasCriticalAnomalies($analysis)) {
                $this->handleCriticalAnomalies($analysis);
            }

            DB::commit();

            return new AnomalyReport(
                analysisId: $analysisId,
                anomalies: $analysis,
                timestamp: microtime(true)
            );

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAnalysisFailure($e, $metrics);
            throw new AnomalyException('Anomaly detection failed: ' . $e->getMessage(), $e);
        }
    }

    protected function detectStatisticalAnomalies(array $metrics): array
    {
        $anomalies = [];

        foreach ($this->thresholds as $metric => $rules) {
            $value = $this->extractMetricValue($metrics, $metric);
            
            if ($value === null) {
                continue;
            }

            // Check for standard deviation anomalies
            if ($this->isStandardDeviationAnomaly($metric, $value)) {
                $anomalies[] = [
                    'type' => 'statistical',
                    'metric' => $metric,
                    'value' => $value,
                    'reason' => 'Standard deviation threshold exceeded'
                ];
            }

            // Check for trend anomalies
            if ($this->isTrendAnomaly($metric, $value)) {
                $anomalies[] = [
                    'type' => 'trend',
                    'metric' => $metric,
                    'value' => $value,
                    'reason' => 'Abnormal trend detected'
                ];
            }
        }

        return $anomalies;
    }

    protected function detectPatternAnomalies(array $metrics): array
    {
        $anomalies = [];

        foreach ($this->patterns as $pattern => $rules) {
            if ($this->matchesPattern($metrics, $pattern)) {
                $anomalies[] = [
                    'type' => 'pattern',
                    'pattern' => $pattern,
                    'metrics' => $metrics,
                    'reason' => $rules['description']
                ];
            }
        }

        return $anomalies;
    }

    protected function detectSecurityAnomalies(array $metrics): array
    {
        return $this->security->detectMetricAnomalies($metrics);
    }

    protected function isStandardDeviationAnomaly(string $metric, $value): bool
    {
        $stats = $this->analyzer->getMetricStatistics($metric);
        $deviations = abs($value - $stats['mean']) / $stats['std_dev'];
        
        return $deviations > $this->thresholds[$metric]['std_dev_threshold'];
    }

    protected function isTrendAnomaly(string $metric, $value): bool
    {
        $trend = $this->analyzer->getMetricTrend($metric);
        return abs($value - $trend['predicted']) > $trend['tolerance'];
    }

    protected function matchesPattern(array $metrics, string $pattern): bool
    {
        $matcher = $this->patterns[$pattern]['matcher'];
        return $matcher($metrics);
    }

    protected function handleCriticalAnomalies(array $analysis): void
    {
        foreach ($analysis['critical'] as $anomaly) {
            Log::critical('Critical anomaly detected', [
                'anomaly' => $anomaly,
                'timestamp' => microtime(true)
            ]);

            $this->security->handleCriticalAnomaly($anomaly);
        }
    }

    protected function extractMetricValue(array $metrics, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $metrics;

        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    protected function generateAnalysisId(): string
    {
        return uniqid('anomaly:', true);
    }
}
