<?php

namespace App\Core\Audit\Interfaces;

interface DataProcessorInterface
{
    public function clean(array $data): self;
    public function normalize(): self;
    public function transform(): self;
    public function validate(): self;
    public function process(): ProcessedDataInterface;
}

interface ProcessedDataInterface
{
    public function getData(): array;
    public function getMetadata(): array;
    public function slice(array $columns): self;
    public function filter(callable $callback): self;
    public function transform(callable $callback): self;
}

interface StatisticalAnalyzerInterface
{
    public function setConfig(StatisticalConfigInterface $config): self;
    public function analyze(ProcessedDataInterface $data): self;
    public function calculateBasicStats(): self;
    public function calculateAdvancedStats(): self;
    public function generateDistributions(): self;
    public function findCorrelations(): self;
    public function getResults(): StatisticalAnalysisInterface;
}

interface PatternDetectorInterface
{
    public function setConfig(PatternConfigInterface $config): self;
    public function detect(ProcessedDataInterface $data): self;
    public function findSequentialPatterns(): self;
    public function findTemporalPatterns(): self;
    public function findBehavioralPatterns(): self;
    public function getResults(): array;
}

interface TrendAnalyzerInterface
{
    public function setConfig(TrendConfigInterface $config): self;
    public function analyze(ProcessedDataInterface $data): self;
    public function detectSeasonality(): self;
    public function detectCycles(): self;
    public function forecastTrends(): self;
    public function getResults(): array;
}

interface AnomalyDetectorInterface
{
    public function setConfig(AnomalyConfigInterface $config): self;
    public function detect(ProcessedDataInterface $data): self;
    public function detectStatisticalAnomalies(): self;
    public function detectPatternAnomalies(): self;
    public function detectContextualAnomalies(): self;
    public function getResults(): array;
}

interface ConfigInterface
{
    public function getStatisticalConfig(): StatisticalConfigInterface;
    public function getPatternConfig(): PatternConfigInterface;
    public function getTrendConfig(): TrendConfigInterface;
    public function getAnomalyConfig(): AnomalyConfigInterface;
    public function getProcessingConfig(): ProcessingConfigInterface;
}

interface StatisticalConfigInterface
{
    public function getConfidenceLevel(): float;
    public function getSignificanceLevel(): float;
    public function getDistributionTypes(): array;
    public function getCorrelationMethods(): array;
}

interface PatternConfigInterface
{
    public function getMinSupport(): float;
    public function getMaxGap(): int;
    public function getMinConfidence(): float;
    public function getPatternTypes(): array;
}

interface TrendConfigInterface
{
    public function getSeasonalityConfig(): array;
    public function getCycleConfig(): array;
    public function getForecastConfig(): array;
}

interface AnomalyConfigInterface
{
    public function getOutlierConfig(): array;
    public function getContextConfig(): array;
    public function getAnomalyTypes(): array;
}

interface ProcessingConfigInterface
{
    public function getCleaningConfig(): array;
    public function getNormalizationConfig(): array;
    public function getTransformationConfig(): array;
}

interface ValidationResultInterface
{
    public function isValid(): bool;
    public function getErrors(): array;
    public function hasError(string $type): bool;
    public function getErrorsByType(string $type): array;
}

interface AnalysisResultInterface
{
    public function getStatistics(): array;
    public function getPatterns(): array;
    public function getTrends(): array;
    public function getAnomalies(): array;
    public function getInsights(): array;
    public function getMetadata(): array;
    public function getSummary(): array;
    public function toArray(): array;
}

interface StatisticalAnalysisInterface
{
    public function getBasicStats(): array;
    public function getAdvancedStats(): array;
    public function getDistributions(): array;
    public function getCorrelations(): array;
    public function getMetadata(): array;
}

interface ValidatorInterface
{
    public function validate($data): ValidationResultInterface;
}
