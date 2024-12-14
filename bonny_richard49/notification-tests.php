<?php

namespace Tests\Feature\Notification;

use Tests\TestCase;
use App\Core\Notification\Models\{
    Notification,
    NotificationTemplate,
    NotificationPreference
};
use App\Core\Notification\Services\NotificationService;
use App\Core\Notification\Events\{
    NotificationCreated,
    NotificationSent
};
use Illuminate\Foundation\Testing\{
    RefreshDatabase,
    WithFaker
};
use Illuminate\Support\Facades\{
    Event,
    Queue,
    Cache
};

class NotificationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock notification channels
        $this->mockNotificationChannels();
    }

    /** @test */
    public function it_can_send_notification_through_multiple_channels()
    {
        Event::fake();
        Queue::fake();

        $user = $this->createUser();
        $template = $this->createTemplate(['channels' => ['mail', 'database']]);
        
        $notification = $this->app->make(NotificationService::class)
            ->send($user, $template, [
                'subject' => 'Test Notification',
                'message' => 'This is a test notification'
            ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'notifiable_id' => $user->id,
            'type' => $template->type
        ]);

        Event::assertDispatched(NotificationCreated::class);
        Queue::assertPushed(SendNotificationJob::class, 2); // One for each channel
    }

    /** @test */
    public function it_respects_user_notification_preferences()
    {
        Queue::fake();

        $user = $this->createUser();
        $template = $this->createTemplate(['channels' => ['mail', 'database', 'sms']]);
        
        // Set user preferences to disable SMS
        NotificationPreference::create([
            'user_id' => $user->id,
            'channel' => 'sms',
            'enabled' => false
        ]);

        $notification = $this->app->make(NotificationService::class)
            ->send($user, $template, [
                'subject' => 'Test Notification',
                'message' => 'This is a test notification'
            ]);

        Queue::assertPushed(SendNotificationJob::class, 2); // Only mail and database
        Queue::assertNotPushed(function (SendNotificationJob $job) {
            return $job->channel === 'sms';
        });
    }

    /** @test */
    public function it_handles_failed_notifications_gracefully()
    {
        Event::fake();
        $this->mock('mail.channel')->shouldReceive('send')->andThrow(new \Exception('Mail service unavailable'));

        $user = $this->createUser();
        $template = $this->createTemplate(['channels' => ['mail', 'database']]);
        
        $notification = $this->app->make(NotificationService::class)
            ->send($user, $template, [
                'subject' => 'Test Notification',
                'message' => 'This is a test notification'
            ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'partially_sent'
        ]);

        Event::assertDispatched(NotificationFailed::class);
    }

    /** @test */
    public function it_can_schedule_notifications_for_later_delivery()
    {
        Queue::fake();

        $user = $this->createUser();
        $template = $this->createTemplate();
        $scheduledTime = now()->addHours(2);

        $notification = $this->app->make(NotificationService::class)
            ->schedule($user, $template, [
                'subject' => 'Scheduled Notification',
                'message' => 'This is a scheduled notification'
            ], $scheduledTime);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'scheduled',
            'scheduled_at' => $scheduledTime
        ]);

        Queue::assertPushed(ProcessScheduledNotificationJob::class);
    }

    /** @test */
    public function it_enforces_rate_limits()
    {
        $this->expectException(NotificationRateLimitExceededException::class);

        $user = $this->createUser();
        $template = $this->createTemplate();
        $service = $this->app->make(NotificationService::class);

        // Send notifications up to the rate limit
        for ($i = 0; $i < config('notifications.rate_limit', 60); $i++) {
            $service->send($user, $template, [
                'subject' => "Test {$i}",
                'message' => "Message {$i}"
            ]);
        }

        // This should throw an exception
        $service->send($user, $template, [
            'subject' => 'Over Limit',
            'message' => 'This should fail'
        ]);
    }

    /** @test */
    public function it_caches_notification_preferences()
    {
        Cache::spy();

        $user = $this->createUser();
        $service = $this->app->make(NotificationService::class);

        $service->getUserPreferences($user);

        Cache::shouldHaveReceived('remember')
            ->with("user:{$user->id}:notification_preferences", ANY, ANY);
    }

    /** @test */
    public function it_validates_notification_templates()
    {
        $this->expectException(NotificationValidationException::class);

        $template = $this->createTemplate([
            'content' => [] // Invalid content
        ]);

        $user = $this->createUser();
        
        $this->app->make(NotificationService::class)
            ->send($user, $template, []);
    }

    /** @test */
    public function it_tracks_notification_metrics()
    {
        $metrics = $this->mock('notification.metrics');
        $metrics->shouldReceive('increment')->times(3); // Created, Queued, Sent

        $user = $this->createUser();
        $template = $this->createTemplate();
        
        $this->app->make(NotificationService::class)
            ->send($user, $template, [
                'subject' => 'Test Notification',
                'message' => 'This is a test notification'
            ]);
    }

    /** @test */
    public function it_supports_notification_templates_with_variables()
    {
        $template = $this->createTemplate([
            'content' => [
                'subject' => 'Hello, {{ name }}',
                'body' => 'Your order #{{ order_id }} has been {{ status }}.'
            ]
        ]);

        $user = $this->createUser();
        
        $notification = $this->app->make(NotificationService::class)
            ->send($user, $template, [
                'name' => 'John',
                'order_id' => '123',
                'status' => 'shipped'
            ]);

        $this->assertEquals(
            'Hello, John',
            $notification->data['subject']
        );

        $this->assertEquals(
            'Your order #123 has been shipped.',
            $notification->data['body']
        );
    }

    protected function createUser()
    {
        return User::factory()->create();
    }

    protected function createTemplate(array $attributes = [])
    {
        return NotificationTemplate::factory()->create($attributes);
    }

    protected function mockNotificationChannels()
    {
        $this->mock('mail.channel');
        $this->mock('database.channel');
        $this->mock('sms.channel');
    }
}