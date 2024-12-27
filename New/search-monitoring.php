<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Log;
use App\Core\Metrics\MetricsCollectorInterface;

class SearchMonitor implements SearchMonitorInterface
{
    private MetricsCollectorInterface $metrics;
    private array $timers = [];

    public function __construct(MetricsCollectorInterface $metrics)
    {
        $this->metrics = $metrics;
    }

    public function startOperation(string $operation): void
    {
        $this->timers[$operation] = microtime(true);
    }

    public function endOperation(string $operation, bool $success = true): void
    {
        if (!isset($this->timers[$operation])) {
            return;
        }

        $duration = microtime(true) - $this->timers[$operation];
        unset($this->timers[$operation]);

        $this->metrics->record([
            'operation' => $operation,
            'duration' => $duration,
            'success' => $success,
            'timestamp' => now()
        ]);

        if ($duration > config('search.slow_threshold', 1.0)) {
            Log::warning('Slow search operation detected', [
                'operation' => $operation,
                'duration' => $duration
            ]);
        }
    }

    public function recordSearchMetrics(string $query, int $resultCount, float $duration): void
    {
        $this->metrics->record([
            'type' => 'search',
            'query_length' => strlen($query),
            'result_count' => $resultCount,
            'duration' => $duration,
            'timestamp' => now()
        ]);

        if ($resultCount === 0) {
            $this->metrics->increment('search.zero_results');
        }
    }

    public function recordIndexMetrics(string $id, int $termCount, float $duration): void
    {
        $this->metrics->record([
            'type' => 'index',
            'document_id' => $id,
            'term_count' => $termCount,
            'duration' => $duration,
            'timestamp' => now()
        ]);

        if ($termCount > config('search.high_term_threshold', 1000)) {
            Log::info('High term count document indexed', [
                'document_id' => $id,
                'term_count' => $termCount
            ]);
        }
    }

    public function recordError(\Throwable $e, array $context = []): void
    {
        $this->metrics->increment('search.errors');

        Log::error('Search operation failed', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

interface SearchMonitorInterface
{
    public function startOperation(string $operation): void;
    public function endOperation(string $operation, bool $success = true): void;
    public function recordSearchMetrics(string $query, int $resultCount, float $duration): void;
    public function recordIndexMetrics(string $id, int $termCount, float $duration): void;
    public function recordError(\Throwable $e, array $context = []): void;
}
