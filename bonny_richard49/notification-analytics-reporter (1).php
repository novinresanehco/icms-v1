<?php

namespace App\Core\Notification\Analytics\Reporter;

class AnalyticsReporter
{
    private array $formatters = [];
    private array $generators = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->initializeFormatters();
        $this->initializeGenerators();
    }

    public function generateReport(string $type, array $data, array $options = []): array
    {
        if (!isset($this->generators[$type])) {
            throw new \InvalidArgumentException("Unknown report type: {$type}");
        }

        $generator = $this->generators[$type];
        $reportData = $generator($data, $options);

        return [
            'type' => $type,
            'data' => $reportData,
            'metadata' => $this->generateMetadata($type, $options),
            'generated_at' => time()
        ];
    }

    public function formatReport(array $report, string $format): string
    {
        if (!isset($this->formatters[$format])) {
            throw new \InvalidArgumentException("Unknown format: {$format}");
        }

        return $this->formatters[$format]($report);
    }

    private function initializeFormatters(): void
    {
        $this->formatters = [
            'json' => function(array $report): string {
                return json_encode($report, JSON_PRETTY_PRINT);
            },

            'csv' => function(array $report): string {
                $output = [];
                foreach ($report['data'] as $row) {
                    $output[] = implode(',', array_map('strval', $row));
                }
                return implode("\n", $output);
            },

            'html' => function(array $report): string {
                $html = "<div class='analytics-report'>\n";
                $html .= "<h2>{$report['type']} Report</h2>\n";
                $html .= "<div class='report-data'>\n";
                $html .= $this->arrayToHtml($report['data']);
                $html .= "</div>\n</div>";
                return $html;
            }
        ];
    }

    private function initializeGenerators(): void
    {
        $this->generators = [
            'summary' => function(array $data, array $options): array {
                return [
                    'total_count' => count($data),
                    'date_range' => [
                        'start' => min(array_column($data, 'timestamp')),
                        'end' => max(array_column($data, 'timestamp'))
                    ],
                    'metrics' => $this->calculateMetrics($data)
                ];
            },

            'trend' => function(array $data, array $options): array {
                $interval = $options['interval'] ?? 86400;
                return $this->calculateTrends($data, $interval);
            },

            'breakdown' => function(array $data, array $options): array {
                $groupBy = $options['group_by'] ?? 'type';
                return $this->generateBreakdown($data, $groupBy);
            }
        ];
    }

    private function generateMetadata(string $type, array $options): array
    {
        return [
            'report_type' => $type,
            'options' => $options,
            'config' => $this->config,
            'version' => '1.0'
        ];
    }

    private function calculateMetrics(array $data): array
    {
        $metrics = [];
        foreach ($data as $item) {
            foreach ($item as $key => $value) {
                if (is_numeric($value)) {
                    if (!isset($metrics[$key])) {
                        $metrics[$key] = ['sum' => 0, 'count' => 0, 'min' => $value, 'max' => $value];
                    }
                    $metrics[$key]['sum'] += $value;
                    $metrics[$key]['count']++;
                    $metrics[$key]['min'] = min($metrics[$key]['min'], $value);
                    $metrics[$key]['max'] = max($metrics[$key]['max'], $value);
                }
            }
        }

        foreach ($metrics as $key => $values) {
            $metrics[$key]['avg'] = $values['sum'] / $values['count'];
        }

        return $metrics;
    }

    private function calculateTrends(array $data, int $interval): array
    {
        $trends = [];
        foreach ($data as $item) {
            $bucket = floor($item['timestamp'] / $interval) * $interval;
            if (!isset($trends[$bucket])) {
                $trends[$bucket] = ['count' => 0, 'values' => []];
            }
            $trends[$bucket]['count']++;
            $trends[$bucket]['values'][] = $item;
        }

        return array_map(function($bucket) {
            return [
                'count' => $bucket['count'],
                'metrics' => $this->calculateMetrics($bucket['values'])
            ];
        }, $trends);
    }

    private function generateBreakdown(array $data, string $groupBy): array
    {
        $breakdown = [];
        foreach ($data as $item) {
            $key = $item[$groupBy] ?? 'unknown';
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [];
            }
            $breakdown[$key][] = $item;
        }

        return array_map(function($group) {
            return [
                'count' => count($group),
                'metrics' => $this->calculateMetrics($group)
            ];
        }, $breakdown);
    }

    private function arrayToHtml(array $data, int $depth = 0): string
    {
        $html = '';
        $indent = str_repeat('  ', $depth);

        foreach ($data as $key => $value) {
            $html .= "{$indent}<div class='report-item'>\n";
            $html .= "{$indent}  <span class='key'>{$key}</span>: ";
            
            if (is_array($value)) {
                $html .= "\n" . $this->arrayToHtml($value, $depth + 1);
            } else {
                $html .= "<span class='value'>" . htmlspecialchars($value) . "</span>\n";
            }
            
            $html .= "{$indent}</div>\n";
        }

        return $html;
    }
}
