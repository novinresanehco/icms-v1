<?php

namespace App\Repositories;

use App\Models\Report;
use App\Repositories\Contracts\ReportRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReportRepository extends BaseRepository implements ReportRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'type'];
    protected array $filterableFields = ['status', 'category', 'created_by'];

    public function createReport(array $data): Report
    {
        $report = $this->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'query' => $data['query'],
            'filters' => $data['filters'] ?? [],
            'columns' => $data['columns'],
            'settings' => $data['settings'] ?? [],
            'schedule' => $data['schedule'] ?? null,
            'status' => 'active',
            'created_by' => auth()->id()
        ]);

        Cache::tags(['reports'])->flush();
        return $report;
    }

    public function scheduleReport(int $id, array $schedule): bool
    {
        try {
            $result = $this->update($id, [
                'schedule' => $schedule,
                'last_scheduled_at' => now()
            ]);

            Cache::tags(['reports'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error scheduling report: ' . $e->getMessage());
            return false;
        }
    }

    public function generateReport(int $id, array $parameters = []): array
    {
        try {
            $report = $this->findById($id);
            $generator = app('App\Services\Report\ReportGenerator');
            return $generator->generate($report, $parameters);
        } catch (\Exception $e) {
            \Log::error('Error generating report: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getReportHistory(int $reportId): Collection
    {
        return $this->model->find($reportId)
            ->history()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function saveReportResult(int $reportId, array $result): bool
    {
        try {
            return app('App\Models\ReportHistory')->create([
                'report_id' => $reportId,
                'parameters' => $result['parameters'] ?? [],
                'results' => $result['data'],
                'execution_time' => $result['execution_time'] ?? 0,
                'created_by' => auth()->id()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saving report result: ' . $e->getMessage());
            return false;
        }
    }
}
