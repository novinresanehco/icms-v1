<?php

namespace App\Core\Health;

class HealthStorage 
{
    private DB $db;
    
    public function storeReport(HealthReport $report): void
    {
        DB::transaction(function() use ($report) {
            $reportId = DB::table('health_reports')->insertGetId([
                'status' => $report->overall->value,
                'created_at' => $report->timestamp
            ]);

            foreach ($report->results as $check => $result) {
                DB::table('health_checks')->insert([
                    'report_id' => $reportId,
                    'check_name' => $check,
                    'status' => $result->status->value,
                    'message' => $result->message,
                    'metrics' => json_encode($result->metrics),
                    'created_at' => $report->timestamp
                ]);
            }
        });
    }

    public function getLatestReport(): ?HealthReport
    {
        $report = DB::table('health_reports')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$report) {
            return null;
        }

        $checks = DB::table('health_checks')
            ->where('report_id', $report->id)
            ->get();

        $results = [];
        foreach ($checks as $check) {
            $results[$check->check_name] = new HealthResult(
                HealthStatus::from($check->status),
                $check->message,
                json_decode($check->metrics, true) ?? []
            );
        }

        return new HealthReport($results);
    }

    public function getHistoricalData(string $check, \DateTime $from, \DateTime $to): array
    {
        return DB::table('health_checks')
            ->join('health_reports', 'health_reports.id', '=', 'health_checks.report_id')
            ->where('check_name', $check)
            ->whereBetween('health_reports.created_at', [$from, $to])
            ->orderBy('health_reports.created_at', 'desc')
            ->get()
            ->map(function($record) {
                return [
                    'timestamp' => $record->created_at,
                    'status' => $record->status,
                    'message' => $record->message,
                    'metrics' => json_decode($record->metrics, true)
                ];
            })
            ->toArray();
    }

    public function pruneOldReports(int $daysToKeep = 30): int
    {
        $cutoff = now()->subDays($daysToKeep);

        return DB::transaction(function() use ($cutoff) {
            $reports = DB::table('health_reports')
                ->where('created_at', '<', $cutoff)
                ->get();

            foreach ($reports as $report) {
                DB::table('health_checks')
                    ->where('report_id', $report->id)
                    ->delete();
            }

            return DB::table('health_reports')
                ->where('created_at', '<', $cutoff)
                ->delete();
        });
    }
}
