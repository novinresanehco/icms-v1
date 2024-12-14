<?php

namespace App\Core\Monitoring\Event;

class EventMonitor {
    private EventCollector $collector;
    private EventAnalyzer $analyzer;
    private PatternDetector $patternDetector;
    private AnomalyDetector $anomalyDetector;
    private AlertManager $alertManager;

    public function __construct(
        EventCollector $collector,
        EventAnalyzer $analyzer,
        PatternDetector $patternDetector,
        AnomalyDetector $anomalyDetector,
        AlertManager $alertManager
    ) {
        $this->collector = $collector;
        $this->analyzer = $analyzer;
        $this->patternDetector = $patternDetector;
        $this->anomalyDetector = $anomalyDetector;
        $this->alertManager = $alertManager;
    }

    public function monitor(): EventReport 
    {
        $events = $this->collector->collect();
        $analysis = $this->analyzer->analyze($events);
        $patterns = $this->patternDetector->detectPatterns($events);
        $anomalies = $this->anomalyDetector->detectAnomalies($events);

        if ($this->shouldAlert($analysis, $anomalies)) {
            $this->alertManager->dispatch(
                new EventAlert($analysis, $anomalies)
            );
        }

        return new EventReport($events, $analysis, $patterns, $anomalies);
    }

    private function shouldAlert(EventAnalysis $analysis, array $anomalies): bool 
    {
        return $analysis->hasCriticalEvents() || !empty($anomalies);
    }
}

class EventCollector {
    private EventStore $eventStore;
    private EventFilter $filter;
    private array $sources;

    public function collect(): array 
    {
        $events = [];

        foreach ($this->sources as $source) {
            $sourceEvents = $this->eventStore->getEvents($source);
            $filteredEvents = array_filter($sourceEvents, [$this->filter, 'accept']);
            $events = array_merge($events, $filteredEvents);
        }

        return $events;
    }
}

class EventAnalyzer {
    private FrequencyAnalyzer $frequencyAnalyzer;
    private CorrelationAnalyzer $correlationAnalyzer;
    private ImpactAnalyzer $impactAnalyzer;
    private array $thresholds;

    public function analyze(array $events): EventAnalysis 
    {
        $frequency = $this->frequencyAnalyzer->analyze($events);
        $correlations = $this->correlationAnalyzer->analyze($events);
        $impact = $this->impactAnalyzer->analyze($events);

        $issues = $this->detectIssues($frequency, $correlations, $impact);

        return new EventAnalysis($frequency, $correlations, $impact, $issues);
    }

    private function detectIssues(
        FrequencyAnalysis $frequency,
        CorrelationAnalysis $correlations,
        ImpactAnalysis $impact
    ): array {
        $issues = [];

        if ($frequency->exceedsThreshold($this->thresholds['frequency'])) {
            $issues[] = new EventIssue('high_frequency', $frequency->getMetrics());
        }

        if ($correlations->hasStrongCorrelations()) {
            $issues[] = new EventIssue('strong_correlation', $correlations->getStrongCorrelations());
        }

        if ($impact->isSignificant()) {
            $issues[] = new EventIssue('high_impact', $impact->getMetrics());
        }

        return $issues;
    }
}

class PatternDetector {
    private array $patterns;
    private SequenceMatcher $sequenceMatcher;
    private FrequencyAnalyzer $frequencyAnalyzer;

    public function detectPatterns(array $events): array 
    {
        $detectedPatterns = [];

        foreach ($this->patterns as $pattern) {
            $matches = $this->findPatternMatches($events, $pattern);
            if (!empty($matches)) {
                $detectedPatterns[] = new DetectedPattern($pattern, $matches);
            }
        }

        $sequences = $this->sequenceMatcher->findSequences($events);
        $frequencies = $this->frequencyAnalyzer->findFrequentPatterns($events);

        return array_merge($detectedPatterns, $sequences, $frequencies);
    }

    private function findPatternMatches(array $events, EventPattern $pattern): array 
    {
        $matches = [];
        $windowSize = $pattern->getWindowSize();

        for ($i = 0; $i <= count($events) - $windowSize; $i++) {
            $window = array_slice($events, $i, $windowSize);
            if ($pattern->matches($window)) {
                $matches[] = new PatternMatch($window, $i);
            }
        }

        return $matches;
    }
}

class AnomalyDetector {
    private StatisticalAnalyzer $statisticalAnalyzer;
    private BehaviorAnalyzer $behaviorAnalyzer;
    private array $thresholds;

    public function detectAnomalies(array $events): array 
    {
        $anomalies = [];

        $statistics = $this->statisticalAnalyzer->analyze($events);
        if ($statistics->hasAnomalies()) {
            $anomalies = array_merge($anomalies, $statistics->getAnomalies());
        }

        $behavior = $this->behaviorAnalyzer->analyze($events);
        if ($behavior->hasAnomalies()) {
            $anomalies = array_merge($anomalies, $behavior->getAnomalies());
        }

        return $this->filterSignificantAnomalies($anomalies);
    }

    private function filterSignificantAnomalies(array $anomalies): array 
    {
        return array_filter($anomalies, function(Anomaly $anomaly) {
            return $anomaly->getScore() >= $this->thresholds['anomaly_score'];
        });
    }
}

class EventReport {
    private array $events;
    private EventAnalysis $analysis;
    private array $patterns;
    private array $anomalies;
    private float $timestamp;

    public function __construct(
        array $events,
        EventAnalysis $analysis,
        array $patterns,
        array $anomalies
    ) {
        $this->events = $events;
        $this->analysis = $analysis;
        $this->patterns = $patterns;
        $this->anomalies = $anomalies;
        $this->timestamp = microtime(true);
    }

    public function toArray(): array 
    {
        return [
            'events' => array_map(fn($e) => $e->toArray(), $this->events),
            'analysis' => $this->analysis->toArray(),
            'patterns' => array_map(fn($p) => $p->toArray(), $this->patterns),
            'anomalies' => array_map(fn($a) => $a->toArray(), $this->anomalies),
            'timestamp' => $this->timestamp,
            'summary' => $this->generateSummary()
        ];
    }

    private function generateSummary(): array 
    {
        return [
            'total_events' => count($this->events),
            'critical_events' => $this->analysis->getCriticalEventCount(),
            'pattern_count' => count($this->patterns),
            'anomaly_count' => count($this->anomalies),
            'time_range' => [
                'start' => min(array_map(fn($e) => $e->getTimestamp(), $this->events)),
                'end' => max(array_map(fn($e) => $e->getTimestamp(), $this->events))
            ]
        ];
    }
}

