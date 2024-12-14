<?php

namespace App\Core\Audit;

class PatternDetector
{
    private PatternConfig $config;
    private array $sequentialPatterns = [];
    private array $temporalPatterns = [];
    private array $behavioralPatterns = [];
    private PatternMatcher $matcher;
    private array $data;

    public function setConfig(PatternConfig $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function detect(ProcessedData $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function findSequentialPatterns(): self
    {
        try {
            $this->sequentialPatterns = [
                'frequent_sequences' => $this->findFrequentSequences(),
                'sequence_rules' => $this->generateSequenceRules(),
                'periodic_patterns' => $this->findPeriodicPatterns(),
                'sequence_anomalies' => $this->detectSequenceAnomalies()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new PatternDetectionException(
                "Sequential pattern detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function findTemporalPatterns(): self
    {
        try {
            $this->temporalPatterns = [
                'time_series' => $this->analyzeTimeSeries(),
                'seasonal_patterns' => $this->findSeasonalPatterns(),
                'cyclic_patterns' => $this->findCyclicPatterns(),
                'temporal_associations' => $this->findTemporalAssociations()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new PatternDetectionException(
                "Temporal pattern detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function findBehavioralPatterns(): self
    {
        try {
            $this->behavioralPatterns = [
                'user_behaviors' => $this->analyzeUserBehaviors(),
                'interaction_patterns' => $this->findInteractionPatterns(),
                'usage_patterns' => $this->analyzeUsagePatterns(),
                'behavioral_clusters' => $this->findBehavioralClusters()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new PatternDetectionException(
                "Behavioral pattern detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function getResults(): array
    {
        return [
            'sequential_patterns' => $this->sequentialPatterns,
            'temporal_patterns' => $this->temporalPatterns,
            'behavioral_patterns' => $this->behavioralPatterns,
            'metadata' => [
                'config' => $this->config,
                'timestamp' => now(),
                'pattern_count' => $this->getPatternCount()
            ]
        ];
    }

    protected function findFrequentSequences(): array
    {
        return $this->matcher->findFrequentSequences(
            $this->data,
            $this->config->getMinSupport(),
            $this->config->getMaxGap()
        );
    }

    protected function generateSequenceRules(): array
    {
        return $this->matcher->generateSequenceRules(
            $this->sequentialPatterns['frequent_sequences'],
            $this->config->getMinConfidence()
        );
    }

    protected function findPeriodicPatterns(): array
    {
        return $this->matcher->findPeriodicPatterns(
            $this->data,
            $this->config->getMinPeriodicity()
        );
    }

    protected function analyzeTimeSeries(): array
    {
        return $this->matcher->analyzeTimeSeries(
            $this->data,
            $this->config->getTimeSeriesConfig()
        );
    }

    protected function findSeasonalPatterns(): array
    {
        return $this->matcher->findSeasonalPatterns(
            $this->data,
            $this->config->getSeasonalityConfig()
        );
    }

    protected function analyzeUserBehaviors(): array
    {
        return $this->matcher->analyzeUserBehaviors(
            $this->data,
            $this->config->getBehaviorConfig()
        );
    }

    protected function getPatternCount(): array
    {
        return [
            'sequential' => count($this->sequentialPatterns['frequent_sequences']),
            'temporal' => count($this->temporalPatterns['time_series']),
            'behavioral' => count($this->behavioralPatterns['user_behaviors'])
        ];
    }
}
