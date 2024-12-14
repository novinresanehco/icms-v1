<?php

namespace App\Core\Notification;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\NotificationException;
use Psr\Log\LoggerInterface;

class NotificationManager implements NotificationManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $providers = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function sendNotification(Notification $notification): void
    {
        $notificationId = $this->generateNotificationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('notification:send', [
                'type' => $notification->getType()
            ]);

            $this->validateNotification($notification);
            $this->validateRecipients($notification->getRecipients());

            $providers = $this->getNotificationProviders($notification);
            $this->processNotification($notificationId, $notification, $providers);

            $this->logNotification($notificationId, $notification);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleNotificationFailure($notificationId, $notification, $e);
            throw new NotificationException('Notification sending failed', 0, $e);
        }
    }

    public function registerProvider(NotificationProvider $provider): void
    {
        try {
            $this->security->validateSecureOperation('notification:register_provider', [
                'provider_type' => $provider->getType()
            ]);

            $this->validateProvider($provider);
            $this->providers[$provider->getType()] = $provider;

            $this->logProviderRegistration($provider);

        } catch (\Exception $e) {
            $this->handleProviderRegistrationFailure($provider, $e);
            throw new NotificationException('Provider registration failed', 0, $e);
        }
    }

    private function validateNotification(Notification $notification): void
    {
        if (!$notification->isValid()) {
            throw new NotificationException('Invalid notification structure');
        }

        if (!$this->isNotificationTypeAllowed($notification->getType())) {
            throw new NotificationException('Notification type not allowed');
        }

        if (!$this->validateNotificationContent($notification)) {
            throw new NotificationException('Invalid notification content');
        }
    }

    private function validateRecipients(array $recipients): void
    {
        if (empty($recipients)) {
            throw new NotificationException('No recipients specified');
        }

        foreach ($recipients as $recipient) {
            if (!$this->isValidRecipient($recipient)) {
                throw new NotificationException('Invalid recipient');
            }
        }
    }

    private function processNotification(
        string $id,
        Notification $notification,
        array $providers
    ): void {
        foreach ($providers as $provider) {
            try {
                $this->sendViaProvider($provider, $notification);
            } catch (\Exception $e) {
                $this->handleProviderFailure($id, $provider, $e);
                if ($this->config['fail_fast']) {
                    throw $e;
                }
            }
        }
    }

    private function sendViaProvider(
        NotificationProvider $provider,
        Notification $notification
    ): void {
        $context = $this->createProviderContext($provider, $notification);
        $result = $provider->send($notification, $context);

        $this->validateProviderResult($result);
        $this->logProviderDelivery($provider, $notification, $result);
    }

    private function handleNotificationFailure(
        string $id,
        Notification $notification,
        \Exception $e
    ): void {
        $this->logger->error('Notification delivery failed', [
            'notification_id' => $id,
            'type' => $notification->getType(),
            'error' => $e->getMessage()
        ]);

        if ($this->config['retry_failed']) {
            $this->queueForRetry($id, $notification);
        }
    }

    private function getDefaultConfig(): array
    {
        return [
            'allowed_types' => [
                'email',
                'sms',
                'push',
                'slack',
                'system'
            ],
            'max_recipients' => 100,
            'retry_failed' => true,
            'max_retries' => 3,
            'fail_fast' => false,
            'delivery_timeout' => 30
        ];
    }
}
