<?php

namespace App\Core\Notification;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Support\Facades\Queue;

class NotificationManager implements NotificationInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private array $config;
    private array $handlers;

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeHandlers();
    }

    public function sendCriticalNotification(string $type, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('critical_notification');
        
        try {
            $notification = $this->prepareCriticalNotification($type, $data);
            
            $this->validateNotification($notification);
            
            $this->dispatchNotification($notification);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleNotificationFailure($type, $data, $e);
            throw new NotificationException('Critical notification failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function sendSecurityAlert(string $alert, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('security_alert');
        
        try {
            $notification = $this->prepareSecurityAlert($alert, $data);
            
            $this->validateNotification($notification);
            
            $this->dispatchHighPriorityNotification($notification);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleNotificationFailure($alert, $data, $e);
            throw new NotificationException('Security alert failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function prepareCriticalNotification(string $type, array $data): array
    {
        return [
            'type' => 'critical',
            'notification' => $type,
            'data' => $this->sanitizeNotificationData($data),
            'context' => [
                'timestamp' => microtime(true),
                'system_state' => $this->monitor->captureSystemState(),
                'priority' => 'high'
            ],
            'recipients' => $this->getNotificationRecipients($type)
        ];
    }

    private function prepareSecurityAlert(string $alert, array $data): array
    {
        return [
            'type' => 'security_alert',
            'alert' => $alert,
            'data' => $this->sanitizeNotificationData($data),
            'context' => [
                'timestamp' => microtime(true),
                'security_context' => $this->security->getContext(),
                'priority' => 'critical'
            ],
            'recipients' => $this->getSecurityTeamRecipients()
        ];
    }

    private function dispatchNotification(array $notification): void
    {
        foreach ($this->handlers as $handler) {
            try {
                Queue::push(new SendNotificationJob($handler, $notification));
            } catch (\Exception $e) {
                $this->monitor->recordHandlerFailure($handler, $e);
            }
        }
    }

    private function dispatchHighPriorityNotification(array $notification): void
    {
        foreach ($this->handlers as $handler) {
            try {
                Queue::pushOn(
                    'high-priority',
                    new SendNotificationJob($handler, $notification)
                );
            } catch (\Exception $e) {
                $this->monitor->recordHandlerFailure($handler, $e);
                $this->fallbackNotification($notification, $handler);
            }
        }
    }

    private function fallbackNotification(array $notification, $handler): void
    {
        try {
            $handler->sendImmediate($notification);
        } catch (\Exception $e) {
            $this->monitor->recordFallbackFailure($handler, $e);
        }
    }
}

interface NotificationHandlerInterface
{
    public function send(array $notification): void;
    public function sendImmediate(array $notification): void;
    public function validateNotification(array $notification): bool;
}

class EmailNotificationHandler implements NotificationHandlerInterface
{
    private array $config;
    private SystemMonitor $monitor;

    public function __construct(array $config, SystemMonitor $monitor)
    {
        $this->config = $config;
        $this->monitor = $monitor;
    }

    public function send(array $notification): void
    {
        $monitoringId = $this->monitor->startOperation('email_notification');
        
        try {
            $this->validateNotification($notification);
            
            $email = $this->prepareEmail($notification);
            
            $this->sendEmail($email);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new NotificationHandlerException('Email notification failed', 0, $e);
        }
    }

    public function sendImmediate(array $notification): void
    {
        $this->send($notification);
    }

    public function validateNotification(array $notification): bool
    {
        return isset($notification['recipients']) &&
               isset($notification['data']) &&
               $this->validateEmailAddresses($notification['recipients']);
    }

    private function validateEmailAddresses(array $recipients): bool
    {
        foreach ($recipients as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
        }
        return true;
    }
}
