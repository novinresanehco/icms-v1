<?php

namespace App\Core\Metrics;

interface MetricsInterface 
{
    /**
     * Collect comprehensive system metrics
     *
     * @param Operation $operation Operation to measure
     * @return MetricsResult Collection result with metrics
     * @throws MetricsException If collection fails
     */  
    public function collectMetrics(Operation $operation): MetricsResult;
}

interface Operation
{
    /**
     * Get operation details for metrics
     *
     * @return array Operation details
     */
    public function getDetails(): array;

    /**
     * Get metrics requirements
     *
     * @return array Metrics requirements
     */
    public function getMetricsRequirements(): array;

    /**
     * Convert operation to array
     *
     * @return array Operation data
     */
    public function toArray(): array;
}

class MetricsResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $metricsId,
        public readonly array $metrics
    ) {}
}

class MetricsException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
