<?php

namespace App\Core\Notifications;

use App\Core\Contracts\NotificationInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationManager implements NotificationInterface
{
    private SecurityManager $security;
    private MetricsStore $metricsStore;
    private array $channels = [];
    
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 5; // seconds

    public function __construct(
        SecurityManager $security,
        MetricsStore $metricsStore,
        array $channels = []
    ) {
        $this->security = $security;
        $this->metricsStore = $metricsStore;
        $this->channels = $channels;
    }

    public function send(array $recipients, string $message, array $context = []): void
    {
        DB::beginTransaction();
        
        try {
            // Validate notification
            $this->validateNotification($recipients, $message, $context);
            
            // Prepare notification data
            $notification = $this->prepareNotification($recipients, $message, $context);
            
            // Send through configured channels
            $this->dispatchToChannels($notification);
            
            // Record notification
            $this->recordNotification($notification);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $notification ?? null);
            throw new NotificationException('Failed to send notification: ' . $e->getMessage(), $e);
        }
    }

    public function sendCriticalAlert(string $alert, array $context = []): void
    {
        $recipients = $this->getCriticalAlertRecipients();
        $message = $this->formatCriticalAlert($alert, $context);
        
        try {
            // Send with retries for critical alerts
            $this->sendWithRetry($recipients, $message, $context);
            
        } catch (\Exception $e) {
            // Log failure but don't throw to prevent disrupting operations
            Log::critical('Failed to send critical alert', [
                'alert' => $alert,
                'context' => $context,
                'error' => $e->getMessage()
            ]);
            
            // Execute emergency fallback
            $this->executeFallbackNotification($alert, $context);
        }
    }

    private function validateNotification(array $recipients, string $message, array $context): void
    {
        // Validate recipients
        if (empty($recipients)) {
            throw new ValidationException('No recipients specified');
        }

        // Validate message
        if (empty($message)) {
            throw new ValidationException('Empty message');
        }

        // Validate context
        $this->validateContext($context);
    }

    private function prepareNotification(array $recipients, string $message, array $context): array
    {
        return [
            'id' => uniqid('notif_', true),
            'timestamp' => microtime(true),
            'recipients' => $recipients,
            'message' => $message,
            'context' => $context,
            'security_context' => $this->security->getSecurityContext(),
            'channels' => $this->getEnabledChannels($context)
        ];
    }

    private function dispatchToChannels(array $notification): void
    {
        $results = [];
        
        foreach ($notification['channels'] as $channel) {
            try {
                $results[$channel] = $this->channels[$channel]->send(
                    $notification['recipients'],
                    $notification['message'],
                    $notification['context']
                );
            } catch (\Exception $e) {
                Log::error("Channel {$channel} failed", [
                    'error' => $e->getMessage(),
                    'notification' => $notification
                ]);
                $results[$channel] = false;
            }
        }

        // Verify at least one channel succeeded
        if (!in_array(true, $results, true)) {
            throw new NotificationException('All notification channels failed');
        }
    }

    private function recordNotification(array $notification): void
    {
        DB::table('notifications')->insert([
            'notification_id' => $notification['id'],
            'type' => $notification['context']['type'] ?? 'general',
            'severity' => $notification['context']['severity'] ?? 'info',
            'recipients' => json_encode($notification['recipients']),
            'message' => $notification['message'],
            'context' => json_encode($notification['context']),
            'created_at' => now()
        ]);

        // Update statistics
        $this->updateNotificationStats($notification);
    }

    private function sendWithRetry(array $recipients, string $message, array $context): void
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                $this->send($recipients, $message, $context);
                return;
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                if ($attempts < self::RETRY_ATTEMPTS) {
                    sleep(self::RETRY_DELAY);
                }
            }
        }

        throw new NotificationException(
            'Failed to send notification after ' . self::RETRY_ATTEMPTS . ' attempts',
            $lastException
        );
    }

    private function executeFallbackNotification(string $alert, array $context): void
    {
        try {
            // Attempt SMS fallback
            $this->sendEmergencySMS($alert, $context);
        } catch (\Exception $e) {
            Log::emergency('Emergency notification fallback failed', [
                'alert' => $alert,
                'context' => $context,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function updateNotificationStats(array $notification): void
    {
        $type = $notification['context']['type'] ?? 'general';
        $severity = $notification['context']['severity'] ?? 'info';

        DB::table('notification_stats')
            ->where('type', $type)
            ->where('severity', $severity)
            ->updateOrInsert(
                ['type' => $type, 'severity' => $severity],
                [
                    'count' => DB::raw('count + 1'),
                    'last_sent' => now()
                ]
            );
    }

    private function getCriticalAlertRecipients(): array
    {
        return config('notifications.critical_recipients', []);
    }

    private function formatCriticalAlert(string $alert, array $context): string
    {
        $template = config("notifications.templates.critical.{$alert}");
        if (!$template) {
            return $alert;
        }
        
        return $this->replaceTemplateVariables($template, $context);
    }

    private function validateContext(array $context): void
    {
        $requiredFields = ['type', 'severity'];
        
        foreach ($requiredFields as $field) {
            if (!isset($context[$field])) {
                throw new ValidationException("Missing required context field: {$field}");
            }
        }
    }
}
