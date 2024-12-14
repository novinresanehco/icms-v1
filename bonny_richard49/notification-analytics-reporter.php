<?php

namespace App\Core\Notification\Analytics\Reporter;

class AnalyticsReporter
{
    private array $formatters = [];
    private array $generators = [];
    private array $metrics = [];

    public function registerFormatter(string $format, callable $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    public function registerGenerator(string $type, callable $generator): void
    {
        $this->generators[$type] = $generator;
    }

    public function generateReport(string $type, array $data, array $options = []): array
    {
        if (!isset($this->generators[$type])) {
            throw new \InvalidArgumentException("Unknown report type: {$type}");
        }

        $startTime = microtime(true);
        try {
            $report = ($this->generators[$type])($data, $options);
            $this->recordMetrics($type, 'generate', microtime(true) - $startTime, true);
            return $report;
        } catch (\Exception $e) {
            $this->recordMetrics($type, 'generate', microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function formatReport(array $report, string $format, array $options = []): string
    {
        if (!isset($this->formatters[$format])) {
            throw new \InvalidArgumentException("Unknown format: {$format}");
        }

        $startTime = microtime(true);
        try {
            $formatted = ($this->formatters[$format])($report, $options);
            $this->recordMetrics($format, 'format', microtime(true) - $startTime, true);
            return $formatted;
        } catch (\Exception $e) {
            $this->recordMetrics($format, 'format', microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $type, string $operation, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$type])) {
            $this->metrics[$type] = [
                'operations' => [],
                'failures' => 0,
                'total_time' => 0
            ];
        }

        if (!isset($this->metrics[$type]['operations'][$operation])) {
            $this->metrics[$type]['operations'][$operation] = [
                'count' => 0,
                'total_time' => 0,
                'failures' => 0
            ];
        }

        $this->metrics[$type]['total_time'] += $duration;
        $this->metrics[$type]['operations'][$operation]['count']++;
        $this->metrics[$type]['operations'][$operation]['total_time'] += $duration;

        if (!$success) {
            $this->metrics[$type]['failures']++;
            $this->metrics[$type]['operations'][$operation]['failures']++;
        }
    }
}
