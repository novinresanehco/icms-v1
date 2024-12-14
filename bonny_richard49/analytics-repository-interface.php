<?php

namespace App\Core\Analytics\Repository;

use App\Core\Analytics\Models\AnalyticsData;
use App\Core\Analytics\DTO\AnalyticsDTO;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface AnalyticsRepositoryInterface extends RepositoryInterface
{
    /**
     * Record analytics data.
     *
     * @param AnalyticsDTO $data
     * @return AnalyticsData
     */
    public function record(AnalyticsDTO $data): AnalyticsData;

    /**
     * Get page views statistics.
     *
     * @param array $filters
     * @return array
     */
    public function getPageViews(array $filters = []): array;

    /**
     * Get user engagement metrics.
     *
     * @param array $filters
     * @return array
     */
    public function getUserEngagement(array $filters = []): array;

    /**
     * Get content performance metrics.
     *
     * @param array $filters
     * @return array
     */
    public function getContentPerformance(array $filters = []): array;

    /**
     * Get system performance metrics.
     *
     * @param array $filters
     * @return array
     */
    public function getSystemPerformance(array $filters = []): array;

    /**
     * Get user behavior flow.
     *
     * @param array $filters
     * @return array
     */
    public function getUserFlow(array $filters = []): array;

    /**
     * Get conversion metrics.
     *
     * @param array $filters
     * @return array
     */
    public function getConversionMetrics(array $filters = []): array;

    /**
     * Get real-time statistics.
     *
     * @return array
     */
    public function getRealTimeStats(): array;

    /**
     * Get custom reports.
     *
     * @param array $metrics
     * @param array $dimensions
     * @param array $filters
     * @return array
     */
    public function getCustomReport(array $metrics, array $dimensions, array $filters = []): array;

    /**
     * Export analytics data.
     *
     * @param array $filters
     * @param string $format
     * @return string File path
     */
    public function exportData(array $filters, string $format = 'csv'): string;

    /**
     * Get trending content.
     *
     * @param int $limit
     * @param array $filters
     * @return Collection
     */
    public function getTrendingContent(int $limit = 10, array $filters = []): Collection;

    /**
     * Get performance alerts.
     *
     * @return array
     */
    public function getAlerts(): array;
}
