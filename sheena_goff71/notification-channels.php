<?php

namespace App\Core\Notification\Channels;

use App\Core\Notification\Models\Notification;

interface NotificationChannel
{
    public function send(Notification $notification): bool;
}

class EmailChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        try {
            Mail::to($notification->user->email)
                ->send(new NotificationMail($notification));
            return true;
        } catch (\Exception $e) {
            logger()->error("Email notification failed", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

class SMSChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        if (!$notification->user->phone_number) {
            return false;
        }

        try {
            $message = $this->formatMessage($notification);
            return $this->sendSMS(
                $notification->user->phone_number,
                $message
            );
        } catch (\Exception $e) {
            logger()->error("SMS notification failed", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function formatMessage(Notification $notification): string
    {
        // Format message based on notification type and data
        return view("notifications.sms.{$notification->type}", [
            'notification' => $notification
        ])->render();
    }

    protected function sendSMS(string $phoneNumber, string $message): bool
    {
        // Implement SMS sending logic here
        return true;
    }
}

class PushChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        if (!$notification->user->push_tokens) {
            return false;
        }

        try {
            $payload = $this->buildPayload($notification);
            
            foreach ($notification->user->push_tokens as $token) {
                $this->sendPushNotification($token, $payload);
            }
            
            return true;
        } catch (\Exception $e) {
            logger()->error("Push notification failed", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function buildPayload(Notification $notification): array
    {
        return [
            'title' => $notification->data['title'] ?? 'New Notification',
            'body' => $notification->data['body'] ?? '',
            'data' => [
                'type' => $notification->type,
                'id' => $notification->id
            ]
        ];
    }

    protected function sendPushNotification(string $token, array $payload): void
    {
        // Implement push notification logic here
    }
}

class DatabaseChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        try {
            $notification->markAsDelivered();
            return true;
        } catch (\Exception $e) {
            logger()->error("Database notification failed", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
