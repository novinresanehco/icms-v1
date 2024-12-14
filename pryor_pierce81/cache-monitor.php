<?php

namespace App\Core\Cache;

class CacheMonitor
{
    private $metrics;
    private $alerter;
    private $logger;

    const SLOW_OPERATION_THRESHOLD = 50; // milliseconds

    public function recordCacheRead(string $key, float $duration): void
    {
        $this->recordOperation('read', $key, $duration);
    }

    public function recordCacheWrite(string $key, float $duration): void
    {
        $this->recordOperation('write', $key, $duration);
    }

    private function recordOperation(string $type, string $key, float $duration): void
    {
        // Record metrics
        $this->metrics->record("cache_$type", [
            'key' => $key,
            'duration' => $duration,
            'timestamp' => time()
        ]);

        // Check performance
        $durationMs = $duration * 1000;
        if ($durationMs > self::SLOW_OPERATION_THRESHOLD) {
            $this->handleSlowOperation($type, $key, $durationMs);
        }
    }

    private function handleSlowOperation(string $type, string $key, float $duration): void
    {
        $this->logger->warning('Slow cache operation', [
            'type' => $type,
            'key' => $key,
            'duration' => $duration,
            'threshold' => self::SLOW_OPERATION_THRESHOLD
        ]);

        if ($duration > (self::SLOW_OPERATION_THRESHOLD * 2)) {
            $this->alerter->sendAlert('Critical cache performance', [
                'type' => $type,
                'key' => $key,
                'duration' => $duration
            ]);
        }
    }
}
