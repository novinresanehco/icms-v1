<?php

namespace App\Core\Security;

class SecurityMonitor
{
    private $alerts;
    private $logger;

    public function trackSecurityEvent(SecurityEvent $event): void
    {
        try {
            // Log event
            $this->logger->logSecurity([
                'type' => $event->getType(),
                'severity' => $event->getSeverity(),
                'time' => time(),
                'data' => $event->getData()
            ]);

            // Check severity
            if ($event->isCritical()) {
                $this->handleCriticalEvent($event);
            }

            // Track metrics
            $this->recordMetrics($event);

        } catch (\Exception $e) {
            $this->handleMonitorFailure($e);
        }
    }

    private function handleCriticalEvent(SecurityEvent $event): void
    {
        $this->alerts->sendSecurityAlert($event);
        if ($event->requiresLockdown()) {
            $this->initiateSecurityLockdown($event);
        }
    }

    private function initiateSecurityLockdown(SecurityEvent $event): void
    {
        $this->alerts->sendLockdownAlert($event);
        // Implement lockdown procedures
    }

    private function handleMonitorFailure(\Exception $e): void
    {
        error_log("Security monitoring failed: " . $e->getMessage());
        $this->alerts->sendCriticalAlert('Security monitoring failure');
    }
}
