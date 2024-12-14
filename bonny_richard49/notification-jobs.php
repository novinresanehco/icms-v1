<?php

namespace App\Core\Notification\Jobs;

use App\Core\Notification\Models\Notification;
use App\Core\Notification\Services\NotificationService;
use App\Core\Notification\Events\{NotificationSent, NotificationFailed};
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public $backoff = [30, 60, 120];

    protected string $notificationId;
    protected string $channel;
    protected mixed $recipient;
    protected array $data;

    /**
     * Create a new job instance.
     *
     * @param string $notificationId
     * @param string $channel
     * @param mixed $recipient
     * @param array $data
     */
    public function __construct(string $notificationId, string $channel, $recipient, array $data)
    {
        $this->notificationId = $notificationId;
        $this->channel = $channel;
        $this->recipient = $recipient;
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            $notification = Notification::findOrFail($this->notificationId);
            
            $success = $notificationService->sendThroughChannel(
                $notification,
                $this->channel,
                $this->recipient,
                $this->data
            );

            if ($success) {
                event(new NotificationSent($notification, $this->channel));
                $this->markAsDelivered($notification);
            } else {
                throw new \Exception("Failed to send notification through {$this->channel}");
            }

        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Exception $e
     * @return void
     */
    protected function handleFailure(\Exception $e): void
    {
        try {
            $notification = Notification::find($this->notificationId);
            
            if ($notification) {
                event(new NotificationFailed($notification, $this->channel, $e->getMessage()));
                
                if ($this->attempts() >= $this->tries) {
                    $this->markAsFailed($notification, $e->getMessage());
                }
            }

            Log::error('Notification sending failed', [
                'notification_id' => $this->notificationId,
                'channel' => $this->channel,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

        } catch (\Exception $loggingException) {
            Log::error('Failed to log notification failure', [
                'notification_id' => $this->notificationId,
                'error' => $loggingException->getMessage()
            ]);
        }
    }

    /**
     * Mark notification as delivered.
     *
     * @param Notification $notification
     * @return void
     */
    protected function markAsDelivered(Notification $notification): void
    {
        $notification->update([
            'status' => 'delivered',
            'delivered_at' => now()
        ]);
    }

    /**
     * Mark notification as failed.
     *
     * @param Notification $notification
     * @param string $error
     * @return void
     */
    protected function markAsFailed(Notification $notification, string $error): void
    {
        $notification->update([
            'status' => 'failed',
            'error' => $error
        ]);
    }
}

class ProcessScheduledNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $notificationId;

    /**
     * Create a new job instance.
     *
     * @param string $notificationId
     */
    public function __construct(string $notificationId)
    {
        $this->notificationId = $notificationId;
    }

    /**
     * Execute the job.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            $notification = Notification::findOrFail($this->notificationId);

            if ($notification->status !== 'scheduled') {
                Log::info('Skipping cancelled scheduled notification', [
                    'notification_id' => $this->notificationId
                ]);
                return;
            }

            $notification->update(['status' => 'processing']);

            foreach ($notification->data['channels'] as $channel => $data) {
                SendNotificationJob::dispatch(
                    $this->notificationId,
                    $channel,
                    $notification->notifiable->routeNotificationFor($channel),
                    $data
                );
            }

        } catch (\Exception $e) {
            Log::error('Failed to process scheduled notification', [
                'notification_id' => $this->notificationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

class CleanupOldNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $daysToKeep;

    /**
     * Create a new job instance.
     *
     * @param int $daysToKeep
     */
    public function __construct(int $daysToKeep = 30)
    {
        $this->daysToKeep = $daysToKeep;
    }

    /**
     * Execute the job.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            $count = $notificationService->deleteOldNotifications($this->daysToKeep);

            Log::info('Cleaned up old notifications', [
                'deleted_count' => $count,
                'days_kept' => $this->daysToKeep
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cleanup old notifications', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}