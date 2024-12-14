<?php

namespace App\Core\Monitoring\LogAnalysis;

class LogAnalyzer {
    private LogParser $parser;
    private PatternDetector $patternDetector;
    private AnomalyDetector $anomalyDetector;
    private MetricsCollector $metricsCollector;
    private AlertManager $alertManager;

    public function __construct(
        LogParser $parser,
        PatternDetector $patternDetector,
        AnomalyDetector $anomalyDetector,
        MetricsCollector $metricsCollector,
        AlertManager $alertManager
    ) {
        $this->parser = $parser;
        $this->patternDetector = $patternDetector;
        $this->anomalyDetector = $anomalyDetector;
        $this->metricsCollector = $metricsCollector;
        $this->alertManager = $alertManager;
    }

    public function analyze(array $logs): LogAnalysisResult 
    {
        $parsedLogs = $this->parser->parse($logs);
        $patterns = $this->patternDetector->detectPatterns($parsedLogs);
        $anomalies = $this->anomalyDetector->detectAnomalies($parsedLogs);
        
        $metrics = $this->metricsCollector->collectMetrics($parsedLogs);
        
        if (!empty($anomalies)) {
            $this->alertManager->notifyAnomalies($anomalies);
        }

        return new LogAnalysisResult($patterns, $anomalies, $metrics);
    }
}

class LogParser {
    private array $parsers;
    private array $filters;

    public function parse(array $logs): array 
    {
        $parsedLogs = [];
        
        foreach ($logs as $log) {
            foreach ($this->parsers as $parser) {
                if ($parser->canParse($log)) {
                    $parsed = $parser->parse($log);
                    if ($this->shouldInclude($parsed)) {
                        $parsedLogs[] = $parsed;
                    }
                    break;
                }
            }
        }

        return $parsedLogs;
    }

    private function shouldInclude(ParsedLog $log): bool 
    {
        foreach ($this->filters as $filter) {
            if (!$filter->accept($log)) {
                return false;
            }
        }
        return true;
    }
}

class PatternDetector {
    private array $patterns;
    private float $threshold;

    public function detectPatterns(array $logs): array 
    {
        $detectedPatterns = [];
        
        foreach ($this->patterns as $pattern) {
            $matches = $this->findPatternMatches($logs, $pattern);
            if (count($matches) >= $this->threshold) {
                $detectedPatterns[] = [
                    'pattern' => $pattern,
                    'matches' => $matches,
                    'frequency' => count($matches) / count($logs)
                ];
            }
        }

        return $detectedPatterns;
    }

    private function findPatternMatches(array $logs, LogPattern $pattern): array 
    {
        $matches = [];
        foreach ($logs as $log) {
            if ($pattern->matches($log)) {
                $matches[] = $log;
            }
        }
        return $matches;
    }
}

class AnomalyDetector {
    private array $rules;
    private float $sensitivityThreshold;

    public function detectAnomalies(array $logs): array 
    {
        $anomalies = [];
        
        foreach ($this->rules as $rule) {
            $detected = $rule->detect($logs);
            if ($detected->getScore() >= $this->sensitivityThreshold) {
                $anomalies[] = $detected;
            }
        }

        return $anomalies;
    }
}

class MetricsCollector {
    private array $collectors;
    private array $aggregators;

    public function collectMetrics(array $logs): array 
    {
        $metrics = [];
        
        foreach ($this->collectors as $collector) {
            $metrics = array_merge($metrics, $collector->collect($logs));
        }

        foreach ($this->aggregators as $aggregator) {
            $metrics = $aggregator->aggregate($metrics);
        }

        return $metrics;
    }
}

class LogAnalysisResult {
    private array $patterns;
    private array $anomalies;
    private array $metrics;
    private float $timestamp;

    public function __construct(array $patterns, array $anomalies, array $metrics) 
    {
        $this->patterns = $patterns;
        $this->anomalies = $anomalies;
        $this->metrics = $metrics;
        $this->timestamp = microtime(true);
    }

    public function getPatterns(): array 
    {
        return $this->patterns;
    }

    public function getAnomalies(): array 
    {
        return $this->anomalies;
    }

    public function getMetrics(): array 
    {
        return $this->metrics;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }

    public function toArray(): array 
    {
        return [
            'patterns' => $this->patterns,
            'anomalies' => $this->anomalies,
            'metrics' => $this->metrics,
            'timestamp' => $this->timestamp,
            'generated_at' => date('Y-m-d H:i:s', (int)$this->timestamp)
        ];
    }
}

interface LogPattern {
    public function matches(ParsedLog $log): bool;
    public function getDescription(): string;
}

class ErrorPattern implements LogPattern {
    private string $errorType;
    private ?string $messagePattern;

    public function __construct(string $errorType, ?string $messagePattern = null) 
    {
        $this->errorType = $errorType;
        $this->messagePattern = $messagePattern;
    }

    public function matches(ParsedLog $log): bool 
    {
        if ($log->getType() !== $this->errorType) {
            return false;
        }

        if ($this->messagePattern && !preg_match($this->messagePattern, $log->getMessage())) {
            return false;
        }

        return true;
    }

    public function getDescription(): string 
    {
        return sprintf(
            'Error pattern: type=%s, pattern=%s',
            $this->errorType,
            $this->messagePattern ?? 'any'
        );
    }
}

class SequencePattern implements LogPattern {
    private array $sequence;
    private int $maxGap;

    public function __construct(array $sequence, int $maxGap = 5) 
    {
        $this->sequence = $sequence;
        $this->maxGap = $maxGap;
    }

    public function matches(ParsedLog $log): bool 
    {
        $position = 0;
        $lastMatch = -1;

        foreach ($this->sequence as $pattern) {
            $found = false;
            for ($i = $lastMatch + 1; $i < count($log->getSequence()); $i++) {
                if ($pattern->matches($log->getSequence()[$i])) {
                    if ($lastMatch >= 0 && ($i - $lastMatch) > $this->maxGap) {
                        return false;
                    }
                    $lastMatch = $i;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string 
    {
        return sprintf(
            'Sequence pattern: steps=%d, maxGap=%d',
            count($this->sequence),
            $this->maxGap
        );
    }
}

interface AnomalyRule {
    public function detect(array $logs): AnomalyDetection;
}

class FrequencyAnomalyRule implements AnomalyRule {
    private string $metric;
    private float $baselineFrequency;
    private float $deviationThreshold;

    public function detect(array $logs): AnomalyDetection 
    {
        $frequency = $this->calculateFrequency($logs);
        $deviation = abs($frequency - $this->baselineFrequency);
        $score = $deviation / $this->baselineFrequency;

        return new AnomalyDetection(
            'frequency',
            $score,
            [
                'metric' => $this->metric,
                'observed' => $frequency,
                'baseline' => $this->baselineFrequency,
                'deviation' => $deviation
            ]
        );
    }

    private function calculateFrequency(array $logs): float 
    {
        $count = 0;
        foreach ($logs as $log) {
            if ($log->hasMetric($this->metric)) {
                $count++;
            }
        }
        return $count / count($logs);
    }
}

class AnomalyDetection {
    private string $type;
    private float $score;
    private array $details;

    public function __construct(string $type, float $score, array $details) 
    {
        $this->type = $type;
        $this->score = $score;
        $this->details = $details;
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getScore(): float 
    {
        return $this->score;
    }

    public function getDetails(): array 
    {
        return $this->details;
    }
}
