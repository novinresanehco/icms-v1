<?php

namespace App\Core\Notification;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Validation\ValidationService;
use App\Core\Queue\QueueManager;
use App\Core\Logging\AuditLogger;

class NotificationService implements NotificationInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private ValidationService $validator;
    private QueueManager $queue;
    private AuditLogger $logger;
    private array $channels;
    private array $templates;

    public function send(string $recipient, string $message, int $priority = self::PRIORITY_NORMAL): void
    {
        $notificationId = $this->generateNotificationId();
        
        try {
            $this->validateSendRequest($recipient, $message, $priority);
            $this->security->validateAccess('notification.send');

            $channels = $this->determineChannels($recipient, $priority);
            $this->dispatchNotification($notificationId, $recipient, $message, $channels, $priority);

        } catch (\Exception $e) {
            $this->handleSendFailure($e, $recipient, $message, $priority);
            throw $e;
        }
    }

    public function sendBulk(array $recipients, string $message, int $priority = self::PRIORITY_NORMAL): void
    {
        $batchId = $this->generateBatchId();
        
        try {
            $this->validateBulkSendRequest($recipients, $message, $priority);
            $this->security->validateAccess('notification.bulk_send');

            foreach ($recipients as $recipient) {
                $this->queueNotification($batchId, $recipient, $message, $priority);
            }

            $this->processBulkNotifications($batchId);

        } catch (\Exception $e) {
            $this->handleBulkSendFailure($e, $recipients, $message, $priority);
            throw $e;
        }
    }

    public function notifyAdministrators(string $subject, array $data): void
    {
        try {
            $this->security->validateAccess('notification.admin');
            
            $admins = $this->getAdministrators();
            $message = $this->formatAdminMessage($subject, $data);
            
            $this->sendBulk($admins, $message, self::PRIORITY_CRITICAL);
            
        } catch (\Exception $e) {
            $this->handleAdminNotificationFailure($e, $subject, $data);
            throw $e;
        }
    }

    public function notifyEmergency(string $message, array $context = []): void
    {
        try {
            $this->security->validateAccess('notification.emergency');
            
            $emergency = $this->getEmergencyContacts();
            $formattedMessage = $this->formatEmergencyMessage($message, $context);
            
            foreach ($emergency as $contact) {
                $this->sendUrgent($contact, $formattedMessage);
            }
            
        } catch (\Exception $e) {
            $this->handleEmergencyFailure($e, $message, $context);
            throw $e;
        }
    }

    protected function dispatchNotification(
        string $id, 
        string $recipient, 
        string $message, 
        array $channels,
        int $priority
    ): void {
        foreach ($channels as $channel) {
            $this->queue->dispatch(
                'notifications',
                [
                    'id' => $id,
                    'channel' => $channel,
                    'recipient' => $recipient,
                    'message' => $message,
                    'priority' => $priority
                ],
                $priority
            );
        }

        $this->logNotification($id, $recipient, $channels, $priority);
    }

    protected function determineChannels(string $recipient, int $priority): array
    {
        $channels = [];

        if ($priority >= self::PRIORITY_CRITICAL) {
            $channels = ['sms', 'email', 'push', 'voice'];
        } elseif ($priority >= self::PRIORITY_HIGH) {
            $channels = ['email', 'push'];
        } else {
            $channels = ['email'];
        }

        $userChannels = $this->getUserChannels($recipient);
        return array_intersect($channels, $userChannels);
    }

    protected function queueNotification(string $batchId, string $recipient, string $message, int $priority): void
    {
        $channels = $this->determineChannels($recipient, $priority);
        
        $notificationId = $this->generateNotificationId();
        
        $this->dispatchNotification(
            $notificationId,
            $recipient,
            $message,
            $channels,
            $priority
        );

        $this->trackBatchNotification($batchId, $notificationId);
    }

    protected function processBulkNotifications(string $batchId): void
    {
        $this->queue->batch($batchId)
            ->allowFailures(false)
            ->dispatch();
    }

    protected function validateSendRequest(string $recipient, string $message, int $priority): void
    {
        if (!$this->validator->validateRecipient($recipient)) {
            throw new NotificationException('Invalid recipient');
        }

        if (!$this->validator->validateMessage($message)) {
            throw new NotificationException('Invalid message');
        }

        if (!$this->validator->validatePriority($priority)) {
            throw new NotificationException('Invalid priority level');
        }
    }

    protected function validateBulkSendRequest(array $recipients, string $message, int $priority): void
    {
        foreach ($recipients as $recipient) {
            $this->validateSendRequest($recipient, $message, $priority);
        }
    }

    protected function formatAdminMessage(string $subject, array $data): string
    {
        return $this->templates['admin']->render([
            'subject' => $subject,
            'data' => $data,
            'timestamp' => time()
        ]);
    }

    protected function formatEmergencyMessage(string $message, array $context): string
    {
        return $this->templates['emergency']->render([
            'message' => $message,
            'context' => $context,
            'timestamp' => time()
        ]);
    }

    protected function sendUrgent(string $recipient, string $message): void
    {
        $this->send($recipient, $message, self::PRIORITY_EMERGENCY);
    }

    protected function logNotification(string $id, string $recipient, array $channels, int $priority): void
    {
        $this->logger->info('Notification dispatched', [
            'id' => $id,
            'recipient' => $recipient,
            'channels' => $channels,
            'priority' => $priority
        ]);

        $this->metrics->incrementCounter('notifications.sent');
        $this->metrics->incrementCounter("notifications.priority.{$priority}");
    }

    protected function handleSendFailure(\Exception $e, string $recipient, string $message, int $priority): void
    {
        $this->logger->error('Notification send failed', [
            'recipient' => $recipient,
            'priority' => $priority,
            'error' => $e->getMessage()
        ]);

        $this->metrics->incrementCounter('notification_failures');
    }

    private function generateNotificationId(): string
    {
        return 'notif_' . md5(uniqid(mt_rand(), true));
    }

    private function generateBatchId(): string
    {
        return 'batch_' . md5(uniqid(mt_rand(), true));
    }
}
