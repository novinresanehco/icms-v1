<?php

namespace App\Core\Monitoring;

interface MonitoringInterface
{
    /**
     * Monitor operation with full protection and metrics collection
     *
     * @param Operation $operation Operation to monitor
     * @return MonitoringResult The monitoring result with metrics
     * @throws MonitoringException If monitoring fails
     */
    public function monitorOperation(Operation $operation): MonitoringResult;
}

interface Operation
{
    /**
     * Get operation details for monitoring
     *
     * @return array Operation details
     */
    public function getDetails(): array;

    /**
     * Get monitoring requirements
     *
     * @return array Monitoring requirements
     */
    public function getMonitoringRequirements(): array;

    /**
     * Convert operation to array
     *
     * @return array Operation data
     */
    public function toArray(): array;
}

class MonitoringResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $monitoringId,
        public readonly array $metrics
    ) {}
}

class MonitoringException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
