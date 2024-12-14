<?php

namespace App\Core\Repository;

use App\Models\Report;
use App\Core\Events\ReportEvents;
use App\Core\Exceptions\ReportRepositoryException;

class ReportRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Report::class;
    }

    /**
     * Generate report
     */
    public function generateReport(string $type, array $parameters): Report
    {
        try {
            // Create report record
            $report = $this->create([
                'type' => $type,
                'parameters' => $parameters,
                'status' => 'processing',
                'created_by' => auth()->id()
            ]);

            // Process report data
            $data = $this->processReportData($type, $parameters);

            // Update report with results
            $report->update([
                'data' => $data,
                'status' => 'completed',
                'completed_at' => now()
            ]);

            event(new ReportEvents\ReportGenerated($report));
            return $report;

        } catch (\Exception $e) {
            if (isset($report)) {
                $report->update(['status' => 'failed', 'error' => $e->getMessage()]);
            }
            throw new ReportRepositoryException(
                "Failed to generate report: {$e->getMessage()}"
            );
        }
    }

    /**
     * Schedule report generation
     */
    public function scheduleReport(string $type, array $parameters, string $schedule): Report
    {
        try {
            $report = $this->create([
                'type' => $type,
                'parameters' => $parameters,
                'schedule' => $schedule,
                'status' => 'scheduled',
                'created_by' => auth()->id()
            ]);

            event(new ReportEvents\ReportScheduled($report));
            return $report;

        } catch (\Exception $e) {
            throw new ReportRepositoryException(
                "Failed to schedule report: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get user reports
     */
    public function getUserReports(int $userId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("user.{$userId}"),
            $this->cacheTime,
            fn() => $this->model->where('created_by', $userId)
                               ->latest()
                               ->get()
        );
    }

    /**
     * Export report
     */
    public function exportReport(int $reportId, string $format): string
    {
        try {
            $report = $this->find($reportId);
            if (!$report) {
                throw new ReportRepositoryException("Report not found with ID: {$reportId}");
            }

            $exportPath = $this->exportToFormat($report, $format);
            
            event(new ReportEvents\ReportExported($report, $format));
            return $exportPath;

        } catch (\Exception $e) {
            throw new ReportRepositoryException(
                "Failed to export report: {$e->getMessage()}"
            );
        }
    }

    /**
     * Process report data
     */
    protected function processReportData(string $type, array $parameters): array
    {
        // Implementation depends on report type
        switch ($type) {
            case 'user_activity':
                return $this->processUserActivityReport($parameters);
            case 'content_analytics':
                return $this->processContentAnalyticsReport($parameters);
            case 'system_performance':
                return $this->processSystemPerformanceReport($parameters);
            default:
                throw new ReportRepositoryException("Unsupported report type: {$type}");
        }
    }
}
