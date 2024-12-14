// File: app/Core/Search/Analysis/SearchAnalyzer.php
<?php

namespace App\Core\Search\Analysis;

class SearchAnalyzer
{
    protected MetricsCollector $metrics;
    protected PatternAnalyzer $patternAnalyzer;
    protected TrendAnalyzer $trendAnalyzer;

    public function analyze(SearchResult $result): AnalysisResult
    {
        $metrics = $this->collectMetrics($result);
        $patterns = $this->patternAnalyzer->analyze($result);
        $trends = $this->trendAnalyzer->analyze($result);

        return new AnalysisResult([
            'metrics' => $metrics,
            'patterns' => $patterns,
            'trends' => $trends,
            'recommendations' => $this->generateRecommendations($metrics, $patterns, $trends)
        ]);
    }

    protected function collectMetrics(SearchResult $result): array
    {
        return $this->metrics->collect([
            'total_hits' => $result->getTotalHits(),
            'response_time' => $result->getResponseTime(),
            'relevancy_score' => $result->getRelevancyScore(),
            'filter_usage' => $result->getFilterUsage()
        ]);
    }

    protected function generateRecommendations(array $metrics, array $patterns, array $trends): array
    {
        $recommendations = [];

        if ($metrics['relevancy_score'] < $this->config->getMinRelevancyScore()) {
            $recommendations[] = 'Consider adjusting search weights or adding synonyms';
        }

        // Add more recommendation logic

        return $recommendations;
    }
}
