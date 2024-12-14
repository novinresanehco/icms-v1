<?php

namespace App\Core\Notification\Analytics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnalyticsProcessed
{
    use Dispatchable, SerializesModels;

    public string $type;
    public array $results;
    public array $metadata;

    /**
     * Create a new event instance.
     *
     * @param string $type
     * @param array $results
     */
    public function __construct(string $type, array $results)
    {
        $this->type = $type;
        $this->results = $results;
        $this->metadata = [
            'processed_at' => now(),
            'processing_time' => microtime(true) - LARAVEL_START
        ];
    }
}

class AnalyticsOptimizationNeeded
{
    use Dispatchable, SerializesModels;

    public string $channel;
    public array $metrics;
    public array $suggestions;

    /**
     * Create a new event instance.
     *
     * @param string $channel
     * @param array $metrics
     * @param array $suggestions
     */
    public function __construct(string $channel, array $metrics, array $suggestions)
    {
        $this->channel = $channel;
        $this->metrics = $metrics;
        $this->suggestions = $suggestions;
    }
}

class AnomalyDetected
{
    use Dispatchable, SerializesModels;

    public string $metricType;
    public array $anomalyData;
    public float $severity;

    /**
     * Create a new event instance.
     *
     * @param string $metricType
     * @param array $anomalyData
     * @param float $severity
     */
    public function __construct(string $metricType, array $anomalyData, float $severity)
    {
        $this->metricType = $metricType;
        $this->anomalyData = $anomalyData;
        $this->severity = $severity;
    }
}
