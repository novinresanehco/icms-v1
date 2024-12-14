// app/Core/Notification/Contracts/NotificationServiceInterface.php
<?php

namespace App\Core\Notification\Contracts;

interface NotificationServiceInterface
{
    /**
     * Send a notification
     *
     * @param NotifiableInterface $notifiable
     * @param NotificationInterface $notification  
     * @return void
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void;

    /**
     * Schedule a notification for later delivery
     * 
     * @param NotifiableInterface $notifiable
     * @param NotificationInterface $notification
     * @param \DateTime $scheduledTime
     * @return void
     */
    public function schedule(NotifiableInterface $notifiable, NotificationInterface $notification, \DateTime $scheduledTime): void;

    /**
     * Cancel a scheduled notification
     *
     * @param string $notificationId
     * @return bool
     */
    public function cancelScheduled(string $notificationId): bool;

    /**
     * Get notification history for a notifiable entity
     *
     * @param NotifiableInterface $notifiable
     * @param array $filters
     * @return array
     */
    public function getHistory(NotifiableInterface $notifiable, array $filters = []): array;
}

// app/Core/Notification/Contracts/NotifiableInterface.php
<?php

namespace App\Core\Notification\Contracts;

interface NotifiableInterface
{
    /**
     * Get notification routing information for the given channel
     *
     * @param string $channel
     * @return mixed
     */
    public function routeNotificationFor(string $channel);

    /**
     * Get notification preferences
     *
     * @return array
     */
    public function getNotificationPreferences(): array;

    /**
     * Get notification meta data
     *
     * @return array
     */
    public function getNotificationMetaData(): array;
}

// app/Core/Notification/Contracts/NotificationInterface.php
<?php

namespace App\Core\Notification\Contracts;

interface NotificationInterface
{
    /**
     * Get available notification channels
     *
     * @return array
     */
    public function via(): array;

    /**
     * Get notification data for channel
     *
     * @param string $channel
     * @return array
     */
    public function toChannel(string $channel): array;

    /**
     * Get notification ID
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Get notification type
     *
     * @return string  
     */
    public function getType(): string;
}