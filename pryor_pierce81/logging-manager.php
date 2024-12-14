<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\LoggingException;
use Psr\Log\LoggerInterface;

class LoggingManager implements LoggingManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $activeLoggers = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function logSystemEvent(SystemEvent $event): void
    {
        $eventId = $this->generateEventId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('logging:system', [
                'event_type' => $event->getType()
            ]);

            $this->validateSystemEvent($event);
            $this->processSystemEvent($event);

            if ($event->isCritical()) {
                $this->handleCriticalEvent($event);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($eventId, $event, $e);
            throw new LoggingException('System event logging failed', 0, $e);
        }
    }

    public function logPerformanceMetrics(array $metrics): void
    {
        $metricsId = $this->generateMetricsId();

        try {
            $this->validateMetrics($metrics);
            $this->processPerformanceMetrics($metrics);

            if ($this->detectPerformanceIssues($metrics)) {
                $this->triggerPerformanceAlert($metrics);
            }

            $this->storeMetrics($metrics);

        } catch (\Exception $e) {
            $this->handleMetricsFailure($metricsId, $metrics, $e);
            throw new LoggingException('Performance metrics logging failed', 0, $e);
        }
    }

    public function getSystemLogs(array $criteria): array
    {
        try {
            $this->security->validateSecureOperation('logging:retrieve', $criteria);
            $this->validateLogCriteria($criteria);

            $logs = $this->retrieveSystemLogs($criteria);
            $this->validateLogs($logs);

            return $logs;

        } catch (\Exception $e) {
            $this->handleLogRetrievalFailure($criteria, $e);
            throw new LoggingException('Log retrieval failed', 0, $e);
        }
    }

    private function validateSystemEvent(SystemEvent $event): void
    {
        if (!$event->isValid()) {
            throw new LoggingException('Invalid system event');
        }

        foreach ($this->config['required_fields'] as $field) {
            if (!$event->hasField($field)) {
                throw new LoggingException("Missing required field: {$field}");
            }
        }
    }

    private function processSystemEvent(SystemEvent $event): void
    {
        $entry = $this->createLogEntry($event);
        
        foreach ($this->activeLoggers as $logger) {
            $logger->log($entry);
        }

        if ($event->getLevel() >= $this->config['alert_threshold']) {
            $this->notifySystemEvent($event);
        }
    }

    private function handleCriticalEvent(SystemEvent $event): void
    {
        $alert = new SystemAlert($event);
        
        foreach ($this->config['critical_handlers'] as $handler) {
            try {
                $handler->handleCriticalEvent($event);
            } catch (\Exception $e) {
                $this->logger->error('Critical event handler failed', [
                    'handler' => get_class($handler),
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function handleLoggingFailure(string $id, $event, \Exception $e): void
    {
        $this->logger->critical('Logging operation failed', [
            'event_id' => $id,
            'event_type' => get_class($event),
            'error' => $e->getMessage()
        ]);

        if ($this->config['emergency_logging_enabled']) {
            $this->logToEmergencyChannel($id, $event, $e);
        }
    }

    private function getDefaultConfig(): array
    {
        return [
            'log_retention' => 30 * 86400,
            'alert_threshold' => LogLevel::ERROR,
            'emergency_logging_enabled' => true,
            'required_fields' => ['timestamp', 'level', 'message'],
            'max_log_size' => 10485760
        ];
    }
}
