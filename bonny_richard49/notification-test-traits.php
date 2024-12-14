<?php

namespace Tests\Traits;

use App\Core\Notification\Models\{
    Notification,
    NotificationTemplate,
    NotificationPreference
};
use App\Core\Notification\Services\NotificationService;
use Illuminate\Support\Collection;

trait NotificationTestingHelpers
{
    /**
     * Create a notification with default testing data.
     *
     * @param array $attributes
     * @return Notification
     */
    protected function createNotification(array $attributes = []): Notification
    {
        return Notification::factory()->create($attributes);
    }

    /**
     * Create multiple notifications.
     *
     * @param int $count
     * @param array $attributes
     * @return Collection
     */
    protected function createNotifications(int $count, array $attributes = []): Collection
    {
        return Notification::factory()->count($count)->create($attributes);
    }

    /**
     * Create a notification template.
     *
     * @param array $attributes
     * @return NotificationTemplate
     */
    protected function createTemplate(array $attributes = []): NotificationTemplate
    {
        return NotificationTemplate::factory()->create($attributes);
    }

    /**
     * Create notification preferences for a user.
     *
     * @param int $userId
     * @param array $channels
     * @return Collection
     */
    protected function createPreferences(int $userId, array $channels = []): Collection
    {
        $channels = $channels ?: ['mail', 'database', 'slack', 'sms'];
        
        return collect($channels)->map(function ($channel) use ($userId) {
            return NotificationPreference::factory()->create([
                'user_id' => $userId,
                'channel' => $channel
            ]);
        });
    }

    /**
     * Mock notification channels for testing.
     *
     * @param array $channels
     * @return void
     */
    protected function mockNotificationChannels(array $channels = []): void
    {
        $channels = $channels ?: ['mail', 'database', 'slack', 'sms'];
        
        foreach ($channels as $channel) {
            $this->mock("notification.channel.{$channel}")
                ->shouldReceive('send')
                ->andReturn(true);
        }
    }

    /**
     * Assert notification was created with specific attributes.
     *
     * @param array $attributes
     * @return void
     */
    protected function assertNotificationCreated(array $attributes): void
    {
        $this->assertDatabaseHas('notifications', $attributes);
    }

    /**
     * Assert notification was sent through specific channels.
     *
     * @param Notification $notification
     * @param array $channels
     * @return void
     */
    protected function assertNotificationSentThroughChannels(Notification $notification, array $channels): void
    {
        foreach ($channels as $channel) {
            $this->mock("notification.channel.{$channel}")
                ->shouldHaveReceived('send')
                ->with(
                    Mockery::any(),
                    Mockery::hasKey('notification_id', $notification->id)
                );
        }
    }

    /**
     * Assert notification has specific status.
     *
     * @param Notification $notification
     * @param string $status
     * @return void
     */
    protected function assertNotificationStatus(Notification $notification, string $status): void
    {
        $this->assertEquals($status, $notification->fresh()->status);
    }

    /**
     * Assert user has specific number of unread notifications.
     *
     * @param int $userId
     * @param int $count
     * @return void
     */
    protected function assertUnreadNotificationCount(int $userId, int $count): void
    {
        $unreadCount = Notification::where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->count();
            
        $this->assertEquals($count, $unreadCount);
    }

    /**
     * Assert notification template exists with specific attributes.
     *
     * @param array $attributes
     * @return void
     */
    protected function assertTemplateExists(array $attributes): void
    {
        $this->assertDatabaseHas('notification_templates', $attributes);
    }

    /**
     * Assert user preferences are set correctly.
     *
     * @param int $userId
     * @param array $preferences
     * @return void
     */
    protected function assertUserPreferences(int $userId, array $preferences): void
    {
        foreach ($preferences as $channel => $enabled) {
            $this->assertDatabaseHas('notification_preferences', [
                'user_id' => $userId,
                'channel' => $channel,
                'enabled' => $enabled
            ]);
        }
    }

    /**
     * Create a test notification and assert it was processed correctly.
     *
     * @param array $data
     * @param array $expectedChannels
     * @return Notification
     */
    protected function createAndAssertNotification(array $data, array $expectedChannels): Notification
    {
        $notification = $this->app->make(NotificationService::class)
            ->send($data['user'], $data['template'], $data['data'] ?? []);

        $this->assertNotificationCreated([
            'id' => $notification->id,
            'notifiable_id' => $data['user']->id,
            'type' => $data['template']->type
        ]);

        $this->assertNotificationSentThroughChannels($notification, $expectedChannels);

        return $notification;
    }

    /**
     * Clean up notification related test data.
     *
     * @return void
     */
    protected function cleanupNotificationTestData(): void
    {
        Notification::query()->delete();
        NotificationTemplate::query()->delete();
        NotificationPreference::query()->delete();
    }
}