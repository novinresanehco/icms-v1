<?php

namespace App\Core\Metrics\Http\Controllers;

use App\Core\Metrics\Contracts\MetricsCollectorInterface;
use App\Core\Metrics\Reporting\ReportGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MetricsController
{
    public function __construct(
        private MetricsCollectorInterface $collector,
        private ReportGenerator $reportGenerator
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => 'required|string|max:255',
            'value' => 'required',
            'tags' => 'array'
        ]);

        $this->collector->collect(
            $validated['metric'],
            $validated['value'],
            $validated['tags'] ?? []
        );

        return response()->json(['status' => 'success']);
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => 'required|string',
            'from' => 'required|integer',
            'to' => 'required|integer',
            'type' => 'required|string|in:timeseries,summary',
            'format' => 'required|string|in:json,csv'
        ]);

        $metrics = $this->getMetrics(
            $validated['metric'],
            $validated['from'],
            $validated['to']
        );

        $report = $this->reportGenerator->generate(
            $metrics,
            $validated['type'],
            $validated['format']
        );

        return response()->json([
            'status' => 'success',
            'data' => $report
        ]);
    }

    private function getMetrics(string $metric, int $from, int $to): array
    {
        // Implementation to fetch metrics from storage
        return [];
    }
}
