<?php

namespace App\Core\Monitoring\Error;

class ErrorMonitor {
    private ErrorCollector $collector;
    private ErrorAnalyzer $analyzer;
    private ErrorAggregator $aggregator;
    private TrendDetector $trendDetector;
    private AlertManager $alertManager;

    public function __construct(
        ErrorCollector $collector,
        ErrorAnalyzer $analyzer,
        ErrorAggregator $aggregator,
        TrendDetector $trendDetector,
        AlertManager $alertManager
    ) {
        $this->collector = $collector;
        $this->analyzer = $analyzer;
        $this->aggregator = $aggregator;
        $this->trendDetector = $trendDetector;
        $this->alertManager = $alertManager;
    }

    public function monitor(): ErrorReport 
    {
        $errors = $this->collector->collect();
        $analysis = $this->analyzer->analyze($errors);
        $aggregated = $this->aggregator->aggregate($errors);
        $trends = $this->trendDetector->detectTrends($errors);

        if ($this->shouldAlert($analysis, $trends)) {
            $this->alertManager->dispatch(
                new ErrorAlert($analysis, $trends)
            );
        }

        return new ErrorReport($errors, $analysis, $aggregated, $trends);
    }

    private function shouldAlert(ErrorAnalysis $analysis, TrendAnalysis $trends): bool 
    {
        return $analysis->hasCriticalErrors() || $trends->hasNegativeTrends();
    }
}

class ErrorCollector {
    private LogReader $logReader;
    private ExceptionHandler $exceptionHandler;
    private ErrorFilter $filter;

    public function collect(): array 
    {
        $errors = array_merge(
            $this->logReader->getErrors(),
            $this->exceptionHandler->getExceptions()
        );

        return array_filter($errors, [$this->filter, 'accept']);
    }
}

class ErrorAnalyzer {
    private StackTraceAnalyzer $stackTraceAnalyzer;
    private RootCauseAnalyzer $rootCauseAnalyzer;
    private ImpactAnalyzer $impactAnalyzer;

    public function analyze(array $errors): ErrorAnalysis 
    {
        $stackTraces = $this->analyzeStackTraces($errors);
        $rootCauses = $this->analyzeRootCauses($errors);
        $impact = $this->analyzeImpact($errors);

        return new ErrorAnalysis($stackTraces, $rootCauses, $impact);
    }

    private function analyzeStackTraces(array $errors): array 
    {
        $analyses = [];
        foreach ($errors as $error) {
            $analyses[] = $this->stackTraceAnalyzer->analyze($error->getStackTrace());
        }
        return $analyses;
    }

    private function analyzeRootCauses(array $errors): array 
    {
        $causes = [];
        foreach ($errors as $error) {
            $causes[] = $this->rootCauseAnalyzer->analyze($error);
        }
        return $causes;
    }

    private function analyzeImpact(array $errors): array 
    {
        return $this->impactAnalyzer->analyze($errors);
    }
}

class ErrorAggregator {
    private array $dimensions = ['type', 'location', 'user', 'time'];

    public function aggregate(array $errors): array 
    {
        $aggregations = [];

        foreach ($this->dimensions as $dimension) {
            $aggregations[$dimension] = $this->aggregateByDimension($errors, $dimension);
        }

        return $aggregations;
    }

    private function aggregateByDimension(array $errors, string $dimension): array 
    {
        $grouped = [];

        foreach ($errors as $error) {
            $key = $this->getDimensionKey($error, $dimension);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $error;
        }

        return array_map([$this, 'calculateMetrics'], $grouped);
    }

    private function calculateMetrics(array $errors): array 
    {
        return [
            'count' => count($errors),
            'first_seen' => min(array_map(fn($e) => $e->getTimestamp(), $errors)),
            'last_seen' => max(array_map(fn($e) => $e->getTimestamp(), $errors)),
            'severity' => $this->calculateSeverity($errors)
        ];
    }
}

class TrendDetector {
    private TimeSeriesAnalyzer $timeSeriesAnalyzer;
    private PatternMatcher $patternMatcher;
    private array $thresholds;

    public function detectTrends(array $errors): TrendAnalysis 
    {
        $timeSeries = $this->timeSeriesAnalyzer->analyze($errors);
        $patterns = $this->patternMatcher->findPatterns($errors);

        $trends = [
            'frequency' => $this->analyzeFrequencyTrends($timeSeries),
            'severity' => $this->analyzeSeverityTrends($timeSeries),
            'patterns' => $this->analyzePatterns($patterns)
        ];

        return new TrendAnalysis($trends);
    }

    private function analyzeFrequencyTrends(TimeSeries $series): array 
    {
        return [
            'short_term' => $series->getShortTermTrend(),
            'long_term' => $series->getLongTermTrend(),
            'spikes' => $series->detectSpikes($this->thresholds['spike'])
        ];
    }

    private function analyzeSeverityTrends(TimeSeries $series): array 
    {
        return [
            'trend' => $series->getSeverityTrend(),
            'distribution' => $series->getSeverityDistribution()
        ];
    }

    private function analyzePatterns(array $patterns): array 
    {
        $significant = [];

        foreach ($patterns as $pattern) {
            if ($pattern->getConfidence() >= $this->thresholds['pattern_confidence']) {
                $significant[] = $pattern;
            }
        }

        return $significant;
    }
}

class ErrorReport {
    private array $errors;
    private ErrorAnalysis $analysis;
    private array $aggregations;
    private TrendAnalysis $trends;
    private float $timestamp;

    public function __construct(
        array $errors,
        ErrorAnalysis $analysis,
        array $aggregations,
        TrendAnalysis $trends
    ) {
        $this->errors = $errors;
        $this->analysis = $analysis;
        $this->aggregations = $aggregations;
        $this->trends = $trends;
        $this->timestamp = microtime(true);
    }

    public function toArray(): array 
    {
        return [
            'errors' => array_map(fn($e) => $e->toArray(), $this->errors),
            'analysis' => $this->analysis->toArray(),
            'aggregations' => $this->aggregations,
            'trends' => $this->trends->toArray(),
            'timestamp' => $this->timestamp,
            'summary' => $this->generateSummary()
        ];
    }

    private function generateSummary(): array 
    {
        return [
            'total_errors' => count($this->errors),
            'critical_errors' => $this->analysis->getCriticalErrorCount(),
            'unique_errors' => count($this->aggregations['type']),
            'trend_direction' => $this->trends->getTrendDirection(),
            'most_affected_component' => $this->getMostAffectedComponent()
        ];
    }

    private function getMostAffectedComponent(): string 
    {
        $components = $this->aggregations['location'] ?? [];
        if (empty($components)) {
            return 'unknown';
        }

        $maxCount = 0;
        $mostAffected = '';

        foreach ($components as $component => $stats) {
            if ($stats['count'] > $maxCount) {
                $maxCount = $stats['count'];
                $mostAffected = $component;
            }
        }

        return $mostAffected;
    }
}

