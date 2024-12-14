// File: app/Core/SEO/Optimization/ContentOptimizer.php
<?php

namespace App\Core\SEO\Optimization;

class ContentOptimizer
{
    protected KeywordAnalyzer $keywordAnalyzer;
    protected ReadabilityChecker $readabilityChecker;
    protected DensityAnalyzer $densityAnalyzer;
    protected OptimizationConfig $config;

    public function optimize(Content $content): OptimizationResult
    {
        $result = new OptimizationResult();
        
        // Analyze keywords
        $result->setKeywordAnalysis(
            $this->keywordAnalyzer->analyze($content)
        );
        
        // Check readability
        $result->setReadabilityScore(
            $this->readabilityChecker->check($content)
        );
        
        // Analyze keyword density
        $result->setDensityAnalysis(
            $this->densityAnalyzer->analyze($content)
        );
        
        return $result;
    }

    protected function generateRecommendations(Content $content, OptimizationResult $result): array
    {
        $recommendations = [];
        
        if ($result->getKeywordScore() < $this->config->getMinKeywordScore()) {
            $recommendations[] = new KeywordRecommendation($content);
        }
        
        if ($result->getReadabilityScore() < $this->config->getMinReadabilityScore()) {
            $recommendations[] = new ReadabilityRecommendation($content);
        }
        
        return $recommendations;
    }
}
