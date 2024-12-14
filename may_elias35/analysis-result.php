<?php

namespace App\Core\Audit\Results;

class AnalysisResult
{
    private array $statistics;
    private array $patterns;
    private array $trends;
    private array $anomalies;
    private array $insights;
    private array $metadata;

    public function __construct(array $result)
    {
        $this->statistics = $result['statistics'] ?? [];
        $this->patterns = $result['patterns'] ?? [];
        $this->trends = $result['trends'] ?? [];
        $this->anomalies = $result['anomalies'] ?? [];
        $this->insights = $result['insights'] ?? [];
        $this->metadata = $result['metadata'] ?? [];
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }

    public function getPatterns(): array
    {
        return $this->patterns;
    }

    public function getTrends(): array
    {
        return $this->trends;
    }

    public function getAnomalies(): array
    {
        return $this->anomalies;
    }

    public function getInsights(): array
    {
        return $this->insights;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getSummary(): array
    {
        return [
            'statistics_summary' => $this->getStatisticsSummary(),
            'patterns_summary' => $this->getPatternsSummary(),
            'trends_summary' => $this->getTrendsSummary(),
            'anomalies_summary' => $this->getAnomaliesSummary(),
            'key_insights' => $this->getKeyInsights(),
            'analysis_metadata' => $this->getAnalysisMetadata()
        ];
    }

    public function toArray(): array
    {
        return [
            'statistics' => $this->statistics,
            'patterns' => $this->patterns,
            'trends' => $this->trends,
            'anomalies' => $this->anomalies,
            'insights' => $this->insights,
            'metadata' => $this->metadata
        ];
    }

    private function getStatisticsSummary(): array
    {
        return [
            'basic_stats' => $this->statistics['basic_stats'] ?? [],
            'significant_correlations' => $this->statistics['correlations'] ?? [],
            'key_distributions' => $this->statistics['distributions'] ?? []
        ];
    }

    private function getPatternsSummary(): array
    {
        return [
            'significant_patterns' => $this->patterns['significant_patterns'] ?? [],
            'behavioral_insights' => $this->patterns['behavioral_insights'] ?? []
        ];
    }

    private function getTrendsSummary(): array
    {
        return [
            'major_trends' => $this->trends['major_trends'] ?? [],
            'forecasts' => $this->trends['forecasts'] ?? []
        ];
    }

    private function getAnomaliesSummary(): array
    {
        return [
            'critical_anomalies' => $this->anomalies['critical_anomalies'] ?? [],
            'risk_assessment' => $this->anomalies['risk_assessment'] ?? []
        ];
    }

    private function getKeyInsights(): array
    {
        return array_slice($this->insights, 0, 5);
    }

    private function getAnalysisMetadata(): array
    {
        return [
            'timestamp' => $this->metadata['timestamp'] ?? null,
            'duration' => $this->metadata['duration'] ?? null,
            'version' => $this->metadata['version'] ?? null
        ];
    }
}
