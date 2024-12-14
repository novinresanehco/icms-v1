// File: app/Core/Notification/Manager/NotificationManager.php
<?php

namespace App\Core\Notification\Manager;

class NotificationManager
{
    protected NotificationRepository $repository;
    protected NotificationDispatcher $dispatcher;
    protected ChannelManager $channelManager;
    protected NotificationCache $cache;

    public function send(Notification $notification): void
    {
        DB::beginTransaction();
        try {
            $this->repository->save($notification);
            $this->dispatcher->dispatch($notification);
            $this->cache->invalidate($notification->getRecipient());
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new NotificationException("Failed to send notification: " . $e->getMessage());
        }
    }

    public function sendBatch(array $notifications): void
    {
        DB::beginTransaction();
        try {
            foreach ($notifications as $notification) {
                $this->repository->save($notification);
            }
            
            $this->dispatcher->dispatchBatch($notifications);
            $this->invalidateRecipientCaches($notifications);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new NotificationException("Failed to send batch notifications: " . $e->getMessage());
        }
    }

    public function markAsRead(int $notificationId): void
    {
        $notification = $this->repository->find($notificationId);
        $notification->markAsRead();
        $this->repository->save($notification);
        $this->cache->invalidate($notification->getRecipient());
    }
}

// File: app/Core/Notification/Dispatcher/NotificationDispatcher.php
<?php

namespace App\Core\Notification\Dispatcher;

class NotificationDispatcher
{
    protected array $channels = [];
    protected Prioritizer $prioritizer;
    protected RateLimit $rateLimit;
    protected MetricsCollector $metrics;

    public function dispatch(Notification $notification): void
    {
        $channels = $this->getChannelsForNotification($notification);
        
        foreach ($channels as $channel) {
            if ($this->canSendThroughChannel($channel, $notification)) {
                try {
                    $channel->send($notification);
                    $this->metrics->recordSuccess($channel, $notification);
                } catch (\Exception $e) {
                    $this->metrics->recordFailure($channel, $notification, $e);
                    $this->handleFailure($channel, $notification, $e);
                }
            }
        }
    }

    protected function getChannelsForNotification(Notification $notification): array
    {
        return array_filter($this->channels, function($channel) use ($notification) {
            return $channel->supports($notification);
        });
    }

    protected function canSendThroughChannel(Channel $channel, Notification $notification): bool
    {
        return $this->rateLimit->check($channel, $notification->getRecipient());
    }
}

// File: app/Core/Notification/Channel/EmailChannel.php
<?php

namespace App\Core\Notification\Channel;

class EmailChannel implements NotificationChannel
{
    protected Mailer $mailer;
    protected EmailTemplateRenderer $renderer;
    protected EmailConfig $config;

    public function send(Notification $notification): void
    {
        $email = $this->prepareEmail($notification);
        
        $this->mailer->send(
            $email->getRecipients(),
            $email->getSubject(),
            $email->getContent(),
            $email->getAttachments()
        );
    }

    protected function prepareEmail(Notification $notification): Email
    {
        $template = $this->renderer->render(
            $notification->getTemplate(),
            $notification->getData()
        );

        return new Email([
            'recipients' => $notification->getRecipients(),
            'subject' => $template->getSubject(),
            'content' => $template->getContent(),
            'attachments' => $notification->getAttachments()
        ]);
    }

    public function supports(Notification $notification): bool
    {
        return $notification->hasEmailRecipients();
    }
}

// File: app/Core/Notification/Channel/PushChannel.php
<?php

namespace App\Core\Notification\Channel;

class PushChannel implements NotificationChannel
{
    protected PushService $pushService;
    protected DeviceManager $deviceManager;
    protected PushConfig $config;

    public function send(Notification $notification): void
    {
        $devices = $this->deviceManager->getDevicesForUser(
            $notification->getRecipient()
        );

        foreach ($devices as $device) {
            $this->pushService->send(
                $device,
                $this->preparePushPayload($notification)
            );
        }
    }

    protected function preparePushPayload(Notification $notification): array
    {
        return [
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'data' => $notification->getData(),
            'badge' => $notification->getBadgeCount(),
            'sound' => $notification->getSound()
        ];
    }

    public function supports(Notification $notification): bool
    {
        return $notification->supportsPush() && 
               $this->deviceManager->hasDevices($notification->getRecipient());
    }
}
