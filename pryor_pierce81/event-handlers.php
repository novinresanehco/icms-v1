<?php

namespace App\Core\Events;

class SecurityEventHandler
{
    private $logger;
    private $notifier;
    private $monitor;

    public function handle(SecurityEvent $event): void
    {
        $this->monitor->startEventHandling($event);

        try {
            // Log event
            $this->logger->logSecurityEvent($event);

            // Process based on type
            match($event->getType()) {
                'auth_failure' => $this->handleAuthFailure($event),
                'access_denied' => $this->handleAccessDenied($event),
                'suspicious_activity' => $this->handleSuspiciousActivity($event),
                default => $this->handleGenericSecurityEvent($event)
            };

            // Notify if needed
            if ($event->requiresNotification()) {
                $this->notifier->sendSecurityNotification($event);
            }

        } catch (\Exception $e) {
            $this->handleEventFailure($event, $e);
        }
    }

    private function handleEventFailure(SecurityEvent $event, \Exception $e): void
    {
        $this->monitor->logEventFailure($event, $e);
        throw new EventHandlingException('Security event handling failed', 0, $e);
    }
}

class SystemEventHandler
{
    private $monitor;
    private $logger;

    public function handle(SystemEvent $event): void
    {
        try {
            // Log system event
            $this->logger->logSystemEvent($event);

            // Monitor system health
            if ($event->affectsSystemHealth()) {
                $this->monitor->checkSystemHealth();
            }

            // Track metrics
            $this->monitor->trackEventMetrics($event);

        } catch (\Exception $e) {
            $this->handleSystemEventFailure($event, $e);
        }
    }

    private function handleSystemEventFailure(SystemEvent $event, \Exception $e): void
    {
        // Emergency logging
        error_log("System event handling failed: " . $e->getMessage());
        throw $e;
    }
}
