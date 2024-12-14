<?php

namespace App\Core\Security\Alert;

use App\Core\Security\Models\{AlertEvent, SecurityContext, NotificationChannel};
use Illuminate\Support\Facades\{Cache, DB, Log};

class AlertNotificationSystem
{
    private NotificationDispatcher $dispatcher;
    private AlertPrioritizer $prioritizer;
    private AuditLogger $logger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        NotificationDispatcher $dispatcher,
        AlertPrioritizer $prioritizer,
        AuditLogger $logger,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->dispatcher = $dispatcher;
        $this->prioritizer = $prioritizer;
        $this->logger = $logger;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function triggerAlert(
        string $type,
        array $data,
        SecurityContext $context
    ): void {
        DB::beginTransaction();
        
        try {
            $alert = $this->createAlert($type, $data, $context);
            $priority = $this->prioritizer->calculatePriority($alert);
            
            $this->processAlert($alert, $priority);
            $this->dispatchNotifications($alert, $priority);
            $this->executeResponseProtocol($alert, $priority);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAlertFailure($e, $type, $context);
        }
    }

    public function handleSecurityEvent(
        string $eventType,
        array $eventData,
        SecurityContext $context
    ): void {
        try {
            if ($this->shouldTriggerAlert($eventType, $eventData)) {
                $this->triggerAlert($eventType, $eventData, $context);
            }
            
            $this->logSecurityEvent($eventType, $eventData, $context);
            $this->updateSecurityMetrics($eventType, $eventData);
            
        } catch (\Exception $e) {
            $this->handleEventFailure($e, $eventType, $context);
        }
    }

    private function createAlert(
        string $type,
        array $data,
        SecurityContext $context
    ): AlertEvent {
        return new AlertEvent([
            'type' => $type,
            'data' => $this->sanitizeAlertData($data),
            'context' => $context,
            'timestamp' => microtime(true),
            'source_ip' => $context->getIpAddress(),
            'severity' => $this->calculateSeverity($type, $data),
            'correlation_id' => $this->generateCorrelationId($type, $context)
        ]);
    }

    private function processAlert(AlertEvent $alert, string $priority): void
    {
        // Store alert
        $this->storeAlert($alert);
        
        // Update alert counters
        $this->updateAlertCounters($alert);
        
        // Check alert thresholds
        $this->checkAlertThresholds($alert, $priority);
        
        // Correlate with other alerts
        $this->correlateAlerts($alert);
    }

    private function dispatchNotifications(
        AlertEvent $alert,
        string $priority
    ): void {
        $channels = $this->getNotificationChannels($priority);
        
        foreach ($channels as $channel) {
            $this->dispatchToChannel($alert, $channel);
        }
    }

    private function executeResponseProtocol(
        AlertEvent $alert,
        string $priority
    ): void {
        $protocol = $this->getResponseProtocol($priority);
        
        foreach ($protocol->getActions() as $action) {
            $this->executeProtocolAction($action, $alert);
        }
    }

    private function storeAlert(AlertEvent $alert): void
    {
        DB::table('security_alerts')->insert([
            'type' => $alert->getType(),
            'data' => json_encode($alert->getData()),
            'context' => json_encode($alert->getContext()),
            'timestamp' => $alert->getTimestamp(),
            'source_ip' => $alert->getSourceIp(),
            'severity' => $alert->getSeverity(),
            'correlation_id' => $alert->getCorrelationId()
        ]);

        $this->cacheAlertData($alert);
    }

    private function dispatchToChannel(
        AlertEvent $alert,
        NotificationChannel $channel
    ): void {
        try {
            $this->dispatcher->dispatch(
                $channel,
                $this->formatAlertForChannel($alert, $channel)
            );
            
            $this->logNotificationSuccess($alert, $channel);
            
        } catch (\Exception $e) {
            $this->handleDispatchFailure($e, $alert, $channel);
        }
    }

    private function shouldTriggerAlert(string $eventType, array $eventData): bool
    {
        $thresholds = $this->config->getAlertThresholds($eventType);
        
        foreach ($thresholds as $threshold) {
            if ($this->isThresholdExceeded($threshold, $eventData)) {
                return true;
            }
        }
        
        return false;
    }

    private function correlateAlerts(AlertEvent $alert): void
    {
        $correlatedAlerts = $this->findCorrelatedAlerts($alert);
        
        if (count($correlatedAlerts) >= $this->config->getCorrelationThreshold()) {
            $this->triggerCorrelationAlert($alert, $correlatedAlerts);
        }
    }

    private function handleAlertFailure(
        \Exception $e,
        string $type,
        SecurityContext $context
    ): void {
        $this->logger->logSecurityEvent('alert_processing_failed', [
            'error' => $e->getMessage(),
            'type' => $type,
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementCounter('alert_failures');
        
        throw new AlertProcessingException(
            'Alert processing failed: ' . $e->getMessage(),
            previous: $e
        );
    }
}
