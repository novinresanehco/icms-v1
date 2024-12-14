<?php

namespace App\Core\System;

use App\Core\Interfaces\{
    NotificationInterface,
    QueueManagerInterface
};
use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class NotificationService implements NotificationInterface
{
    private QueueManagerInterface $queue;
    private LoggerInterface $logger;
    private array $config;

    private const PRIORITY_LEVELS = [
        'emergency' => 0,
        'critical' => 1,
        'high' => 2,
        'normal' => 3,
        'low' => 4
    ];

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 300;
    private const ERROR_THRESHOLD = 50;
    private const BATCH_SIZE = 100;

    public function __construct(
        QueueManagerInterface $queue,
        LoggerInterface $logger
    ) {
        $this->queue = $queue;
        $this->logger = $logger;
        $this->config = config('notifications');
    }

    public function send(string $recipient, string $message, string $priority = 'normal'): void
    {
        try {
            $notificationId = $this->createNotificationRecord($recipient, $message, $priority);
            
            $this->queue->push('notifications', [
                'id' => $notificationId,
                'recipient' => $recipient,
                'message' => $message,
                'priority' => $priority
            ], $this->getQueuePriority($priority));

            $this->logNotification($notificationId, $recipient, $priority);
        } catch (\Exception $e) {
            $this->handleNotificationError($e, $recipient, $message);
        }
    }

    public function sendBulk(array $recipients, string $message, string $priority = 'normal'): void
    {
        try {
            DB::beginTransaction();

            $chunks = array_chunk($recipients, self::BATCH_SIZE);
            foreach ($chunks as $chunk) {
                $notificationIds = [];
                foreach ($chunk as $recipient) {
                    $notificationIds[] = $this->createNotificationRecord($recipient, $message, $priority);
                }

                $this->queue->pushBulk('notifications', array_map(
                    fn($id, $recipient) => [
                        'id' => $id,
                        'recipient' => $recipient,
                        'message' => $message,
                        'priority' => $priority
                    ],
                    $notificationIds,
                    $chunk
                ), $this->getQueuePriority($priority));

                $this->logBulkNotification($notificationIds, count($chunk), $priority);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBulkNotificationError($e, $recipients, $message);
        }
    }

    public function sendSecurityAlert(string $recipient, array $alertData): void
    {
        try {
            $alertId = $this->createSecurityAlertRecord($recipient, $alertData);
            
            $this->queue->push('security_alerts', [
                'id' => $alertId,
                'recipient' => $recipient,
                'data' => $alertData
            ], self::PRIORITY_LEVELS['emergency']);

            $this->logSecurityAlert($alertId, $recipient, $alertData);
        } catch (\Exception $e) {
            $this->handleSecurityAlertError($e, $recipient, $alertData);
        }
    }

    public function sendSystemAlert(array $alertData, string $priority = 'high'): void
    {
        try {
            $recipients = $this->getSystemAlertRecipients($alertData['type']);
            
            foreach ($recipients as $recipient) {
                $alertId = $this->createSystemAlertRecord($recipient, $alertData);
                
                $this->queue->push('system_alerts', [
                    'id' => $alertId,
                    'recipient' => $recipient,
                    'data' => $alertData
                ], $this->getQueuePriority($priority));
            }

            $this->logSystemAlert($alertData, count($recipients));
        } catch (\Exception $e) {
            $this->handleSystemAlertError($e, $alertData);
        }
    }

    public function markDelivered(int $notificationId): void
    {
        try {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => 'delivered',
                    'delivered_at' => time(),
                    'attempts' => DB::raw('attempts + 1')
                ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark notification as delivered', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function markFailed(int $notificationId, string $reason): void
    {
        try {
            $notification = DB::table('notifications')
                ->where('id', $notificationId)
                ->first();

            if ($notification->attempts < self::MAX_RETRIES) {
                $this->scheduleRetry($notificationId);
            } else {
                DB::table('notifications')
                    ->where('id', $notificationId)
                    ->update([
                        'status' => 'failed',
                        'error' => $reason,
                        'failed_at' => time()
                    ]);

                $this->logNotificationFailure($notificationId, $reason);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark notification failure', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function createNotificationRecord(string $recipient, string $message, string $priority): int
    {
        return DB::table('notifications')->insertGetId([
            'recipient' => $recipient,
            'message' => $message,
            'priority' => self::PRIORITY_LEVELS[$priority] ?? self::PRIORITY_LEVELS['normal'],
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => time()
        ]);
    }

    protected function createSecurityAlertRecord(string $recipient, array $alertData): int
    {
        return DB::table('security_alert_notifications')->insertGetId([
            'recipient' => $recipient,
            'alert_data' => json_encode($alertData),
            'priority' => self::PRIORITY_LEVELS['emergency'],
            'status' => 'pending',
            'created_at' => time()
        ]);
    }

    protected function createSystemAlertRecord(string $recipient, array $alertData): int
    {
        return DB::table('system_alert_notifications')->insertGetId([
            'recipient' => $recipient,
            'alert_data' => json_encode($alertData),
            'alert_type' => $alertData['type'],
            'status' => 'pending',
            'created_at' => time()
        ]);
    }

    protected function scheduleRetry(int $notificationId): void
    {
        $delay = self::RETRY_DELAY * (DB::table('notifications')
            ->where('id', $notificationId)
            ->value('attempts') + 1);

        $this->queue->later('notifications.retry', [
            'notification_id' => $notificationId
        ], $delay);
    }

    protected function getSystemAlertRecipients(string $alertType): array
    {
        return $this->config['system_alert_recipients'][$alertType] ?? 
               $this->config['system_alert_recipients']['default'] ?? 
               [];
    }

    protected function getQueuePriority(string $priority): int
    {
        return self::PRIORITY_LEVELS[$priority] ?? self::PRIORITY_LEVELS['normal'];
    }

    protected function shouldRetry(\Exception $e): bool
    {
        return !($e instanceof \InvalidArgumentException) && 
               !($e instanceof \LogicException);
    }

    protected function handleNotificationError(\Exception $e, string $recipient, string $message): void
    {
        $this->logger->error('Failed to send notification', [
            'recipient' => $recipient,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->shouldRetry($e)) {
            $notificationId = $this->createFailedNotificationRecord($recipient, $message, $e->getMessage());
            $this->scheduleRetry($notificationId);
        }
    }

    protected function handleBulkNotificationError(\Exception $e, array $recipients, string $message): void
    {
        $this->logger->error('Failed to send bulk notifications', [
            'recipient_count' => count($recipients),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->shouldRetry($e)) {
            foreach ($recipients as $recipient) {
                $notificationId = $this->createFailedNotificationRecord($recipient, $message, $e->getMessage());
                $this->scheduleRetry($notificationId);
            }
        }
    }

    protected function handleSecurityAlertError(\Exception $e, string $recipient, array $alertData): void
    {
        $this->logger->critical('Failed to send security alert', [
            'recipient' => $recipient,
            'alert_type' => $alertData['type'] ?? 'unknown',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Security alerts must be delivered - retry immediately with escalation
        $alertId = $this->createFailedSecurityAlertRecord($recipient, $alertData, $e->getMessage());
        $this->escalateSecurityAlert($alertId);
    }

    protected function handleSystemAlertError(\Exception $e, array $alertData): void
    {
        $this->logger->error('Failed to send system alert', [
            'alert_type' => $alertData['type'] ?? 'unknown',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Log to emergency contacts
        $this->notifyEmergencyContacts($alertData, $e->getMessage());
    }

    protected function createFailedNotificationRecord(string $recipient, string $message, string $error): int
    {
        return DB::table('notifications')->insertGetId([
            'recipient' => $recipient,
            'message' => $message,
            'status' => 'failed',
            'error' => $error,
            'attempts' => 0,
            'created_at' => time()
        ]);
    }

    protected function createFailedSecurityAlertRecord(string $recipient, array $alertData, string $error): int
    {
        return DB::table('security_alert_notifications')->insertGetId([
            'recipient' => $recipient,
            'alert_data' => json_encode($alertData),
            'status' => 'failed',
            'error' => $error,
            'created_at' => time()
        ]);
    }

    protected function escalateSecurityAlert(int $alertId): void
    {
        $this->queue->push('security_alerts.escalation', [
            'alert_id' => $alertId
        ], self::PRIORITY_LEVELS['emergency']);
    }

    protected function notifyEmergencyContacts(array $alertData, string $error): void
    {
        $contacts = $this->config['emergency_contacts'] ?? [];
        
        foreach ($contacts as $contact) {
            $this->queue->push('emergency_notifications', [
                'contact' => $contact,
                'alert_data' => $alertData,
                'error' => $error
            ], self::PRIORITY_LEVELS['emergency']);
        }
    }
}
