<?php

namespace App\Core\Logging;

class CriticalLogger
{
    private $storage;
    private $monitor;
    private $alerter;

    public function logSecurityEvent(SecurityEvent $event): void
    {
        try {
            $data = [
                'type' => $event->getType(),
                'severity' => $event->getSeverity(),
                'timestamp' => time(),
                'data' => $event->getData(),
                'trace' => $event->getTrace()
            ];

            // Store encrypted
            $this->storage->storeSecure('security_log', $data);

            // Alert if critical
            if ($event->isCritical()) {
                $this->alerter->sendSecurityAlert($event);
            }

        } catch (\Exception $e) {
            // Emergency backup logging
            $this->emergencyLog($event, $e);
            throw $e;
        }
    }

    private function emergencyLog(SecurityEvent $event, \Exception $e): void
    {
        error_log(json_encode([
            'event' => $event->toArray(),
            'error' => $e->getMessage(),
            'time' => time()
        ]));
    }
}

class CriticalEventTracker 
{
    private $monitor;
    private $logger;

    public function trackOperation(Operation $op): void
    {
        $startTime = microtime(true);

        try {
            // Pre-execution tracking
            $this->monitor->startOperation($op);

            // Track execution
            $result = $op->execute();

            // Post-execution metrics
            $this->recordMetrics($op, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->handleFailure($op, $e);
            throw $e;
        }
    }

    private function recordMetrics(Operation $op, float $duration): void
    {
        $this->monitor->recordMetrics([
            'operation' => $op->getName(),
            'duration' => $duration,
            'memory' => memory_get_peak_usage(),
            'time' => time()
        ]);
    }
}
