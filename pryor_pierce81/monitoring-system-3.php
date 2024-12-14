<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Log, Cache, DB};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\MonitoringException;

class MonitoringService implements MonitoringInterface 
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private AuditLogger $logger;
    private ThresholdManager $thresholds;

    public function track(string $operation, callable $callback, array $context = []): mixed
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();

        try {
            // Start operation tracking
            $trackingId = $this->startTracking($operation, $context);
            
            // Execute operation
            $result = $callback();

            // Record success metrics
            $this->recordSuccess(
                $operation,
                $trackingId,
                microtime(true) - $startTime,
                memory_get_usage() - $memoryStart
            );

            return $result;

        } catch (\Throwable $e) {
            // Record failure metrics
            $this->recordFailure(
                $operation,
                $trackingId ?? null,
                $e,
                microtime(true) - $startTime,
                memory_get_usage() - $memoryStart
            );

            throw $e;
        }
    }

    public function monitor(string $metric, $value, array $tags = []): void
    {
        // Record metric
        $this->metrics->record($metric, $value, $tags);

        // Check thresholds
        if ($threshold = $this->thresholds->get($metric)) {
            if ($threshold->isExceeded($value)) {
                $this->handleThresholdExceeded($metric, $value, $threshold, $tags);
            }
        }

        // Update real-time stats
        $this->updateRealTimeStats($metric, $value, $tags);
    }

    private function startTracking(string $operation, array $context): string
    {
        $trackingId = $this->generateTrackingId();

        // Create tracking record
        DB::table('operation_tracking')->insert([
            'tracking_id' => $trackingId,
            'operation' => $operation,
            'context' => json_encode($context),
            'start_time' => now(),
            'status' => 'in_progress'
        ]);

        return $trackingId;
    }

    private function recordSuccess(
        string $operation,
        string $trackingId,
        float $duration,
        int $memoryUsed
    ): void {
        // Update tracking record
        DB::table('operation_tracking')
            ->where('tracking_id', $trackingId)
            ->update([
                'status' => 'completed',
                'end_time' => now(),
                'duration' => $duration,
                'memory_used' => $memoryUsed
            ]);

        // Record metrics
        $this->metrics->increment("operation.{$operation}.success");
        $this->metrics->timing("operation.{$operation}.duration", $duration);
        $this->metrics->gauge("operation.{$operation}.memory", $memoryUsed);

        // Log success
        $this->logger->info("Operation completed: {$operation}", [
            'tracking_id' => $trackingId,
            'duration' => $duration,
            'memory_used' => $memoryUsed
        ]);
    }

    private function recordFailure(
        string $operation,
        ?string $trackingId,
        \Throwable $error,
        float $duration,
        int $memoryUsed
    ): void {
        if ($trackingId) {
            // Update tracking record
            DB::table('operation_tracking')
                ->where('tracking_id', $trackingId)
                ->update([
                    'status' => 'failed',
                    'end_time' => now(),
                    'duration' => $duration,
                    'memory_used' => $memoryUsed,
                    'error' => $error->getMessage()
                ]);
        }

        // Record error metrics
        $this->metrics->increment("operation.{$operation}.error");
        $this->metrics->timing("operation.{$operation}.error_duration", $duration);

        // Log error details
        $this->logger->error("Operation failed: {$operation}", [
            'tracking_id' => $trackingId,
            'error' => $error->getMessage(),
            'stack_trace' => $error->getTraceAsString(),
            'duration' => $duration,
            'memory_used' => $memoryUsed
        ]);

        // Send alerts if needed
        $this->alerts->sendErrorAlert($operation, $error, [
            'tracking_id' => $trackingId,
            'duration' => $duration
        ]);
    }

    private function handleThresholdExceeded(
        string $metric,
        $value,
        Threshold $threshold,
        array $tags
    ): void {
        // Log threshold violation
        $this->logger->warning("Threshold exceeded for {$metric}", [
            'value' => $value,
            'threshold' => $threshold->getValue(),
            'tags' => $tags
        ]);

        // Send alert
        $this->alerts->sendThresholdAlert($metric, $value, $threshold, $tags);

        // Record incident
        $this->recordIncident('threshold_exceeded', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold->getValue(),
            'tags' => $tags
        ]);
    }

    private function updateRealTimeStats(string $metric, $value, array $tags): void
    {
        $key = "stats:{$metric}:" . md5(json_encode($tags));
        
        Cache::tags(['monitoring', 'realtime'])->put(
            $key,
            [
                'value' => $value,
                'timestamp' => now()->timestamp,
                'tags' => $tags
            ],
            now()->addMinutes(60)
        );
    }

    private function generateTrackingId(): string
    {
        return sprintf(
            '%s-%s',
            now()->format('YmdHis'),
            bin2hex(random_bytes(8))
        );
    }

    private function recordIncident(string $type, array $data): void
    {
        DB::table('incidents')->insert([
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => now()
        ]);
    }
}
