<?php

namespace App\Core\Notification\Analytics\Contracts;

interface NotificationAnalyticsInterface
{
    /**
     * Analyze notification performance
     *
     * @param array $filters
     * @return array
     * @throws AnalyticsException
     */
    public function analyzePerformance(array $filters = []): array;

    /**
     * Analyze user segmentation
     *
     * @param array $filters
     * @return array
     * @throws AnalyticsException
     */
    public function analyzeUserSegments(array $filters = []): array;

    /**
     * Analyze channel effectiveness
     *
     * @param array $filters
     * @return array
     * @throws AnalyticsException
     */
    public function analyzeChannelEffectiveness(array $filters = []): array;

    /**
     * Analyze A/B test results
     *
     * @param string $testId
     * @return array
     * @throws AnalyticsException
     */
    public function analyzeABTests(string $testId): array;
}

interface AnalyticsDataProvider
{
    /**
     * Gather performance data
     *
     * @param array $filters
     * @return Collection
     */
    public function gatherPerformanceData(array $filters): Collection;

    /**
     * Gather segment data
     *
     * @param array $filters
     * @return Collection
     */
    public function gatherSegmentData(array $filters): Collection;

    /**
     * Gather channel data
     *
     * @param array $filters
     * @return Collection
     */
    public function gatherChannelData(array $filters): Collection;

    /**
     * Gather A/B test data
     *
     * @param string $testId
     * @return Collection
     */
    public function gatherTestData(string $testId): Collection;
}

interface AnalyticsProcessor
{
    /**
     * Process raw analytics data
     *
     * @param Collection $data
     * @param array $options
     * @return array
     */
    public function processData(Collection $data, array $options = []): array;

    /**
     * Generate optimization suggestions
     *
     * @param array $metrics
     * @return array
     */
    public function generateOptimizationSuggestions(array $metrics): array;

    /**
     * Calculate statistical significance
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    public function calculateSignificance(array $data, array $options = []): array;
}
