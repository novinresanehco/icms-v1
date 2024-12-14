<?php

namespace App\Core\Audit;

class AuditFormatter
{
    protected DataSanitizer $sanitizer;
    protected FormatValidator $validator;
    protected OutputTransformer $transformer;

    public function __construct(
        DataSanitizer $sanitizer,
        FormatValidator $validator,
        OutputTransformer $transformer
    ) {
        $this->sanitizer = $sanitizer;
        $this->validator = $validator;
        $this->transformer = $transformer;
    }

    public function format(AuditEvent $event): array
    {
        $data = [
            'id' => $event->getId(),
            'type' => $event->getType(),
            'action' => $event->getAction(),
            'data' => $this->sanitizer->sanitize($event->getData()),
            'user_id' => $event->getUserId(),
            'ip_address' => $event->getIpAddress(),
            'metadata' => $event->getMetadata(),
            'timestamp' => $event->getTimestamp(),
            'trace_id' => $event->getTraceId(),
            'severity' => $event->getSeverity()
        ];

        $this->validator->validate($data);

        return $this->transformer->transform($data);
    }

    public function generateReport(array $events, ReportConfig $config): AuditReport
    {
        $formattedEvents = array_map(
            fn($event) => $this->sanitizer->sanitize($event),
            $events
        );

        $report = new AuditReport();
        $report->setEvents($formattedEvents);

        if ($config->shouldIncludeStats()) {
            $report->setStats($this->calculateStats($formattedEvents));
        }

        if ($config->shouldIncludeSummary()) {
            $report->setSummary($this->generateSummary($formattedEvents));
        }

        if ($config->shouldIncludeAnalytics()) {
            $report->setAnalytics($this->generateAnalytics($formattedEvents));
        }

        $this->validator->validateReport($report);

        return $report;
    }

    public function export(array $events, string $format): string
    {
        $formattedEvents = array_map([$this, 'format'], $events);
        
        return match($format) {
            'json' => $this->exportJson($formattedEvents),
            'csv' => $this->exportCsv($formattedEvents),
            'xml' => $this->exportXml($formattedEvents),
            'pdf' => $this->exportPdf($formattedEvents),
            default => throw new UnsupportedFormatException($format)
        };
    }

    protected function calculateStats(array $events): array
    {
        return [
            'total_events' => count($events),
            'events_by_type' => $this->aggregateByField($events, 'type'),
            'events_by_user' => $this->aggregateByField($events, 'user_id'),
            'events_by_severity' => $this->aggregateByField($events, 'severity'),
            'events_by_hour' => $this->aggregateByTimeFrame($events, 'hour'),
            'average_events_per_day' => $this->calculateAveragePerDay($events)
        ];
    }

    protected function generateSummary(array $events): array
    {
        return [
            'period' => [
                'start' => min(array_column($events, 'timestamp')),
                'end' => max(array_column($events, 'timestamp'))
            ],
            'most_active_users' => $this->getMostActiveUsers($events),
            'common_patterns' => $this->detectPatterns($events),
            'risk_indicators' => $this->analyzeRiskIndicators($events),
            'system_health' => $this->assessSystemHealth($events)
        ];
    }

    protected function generateAnalytics(array $events): array
    {
        return [
            'trends' => $this->analyzeTrends($events),
            'anomalies' => $this->detectAnomalies($events),
            'correlations' => $this->findCorrelations($events),
            'predictions' => $this->generatePredictions($events)
        ];
    }

    private function aggregateByField(array $events, string $field): array
    {
        $aggregated = [];
        foreach ($events as $event) {
            $key = $event[$field];
            $aggregated[$key] = ($aggregated[$key] ?? 0) + 1;
        }
        arsort($aggregated);
        return $aggregated;
    }

    private function aggregateByTimeFrame(array $events, string $frame): array
    {
        $aggregated = [];
        foreach ($events as $event) {
            $key = $this->getTimeFrameKey($event['timestamp'], $frame);
            $aggregated[$key] = ($aggregated[$key] ?? 0) + 1;
        }
        ksort($aggregated);
        return $aggregated;
    }

    private function calculateAveragePerDay(array $events): float
    {
        if (empty($events)) {
            return 0.0;
        }

        $timestamps = array_column($events, 'timestamp');
        $daysDiff = (max($timestamps) - min($timestamps)) / 86400;
        
        return count($events) / ($daysDiff ?: 1);
    }
}
