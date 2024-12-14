// app/Core/Notification/Channels/ChannelManager.php
<?php

namespace App\Core\Notification\Channels;

use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\Log;

class ChannelManager
{
    private array $channels = [];
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function addChannel(string $name, NotificationChannel $channel): void
    {
        $this->channels[$name] = $channel;
    }

    public function send(string $channel, $recipient, array $data): bool
    {
        if (!isset($this->channels[$channel])) {
            throw new \InvalidArgumentException("Unknown notification channel: {$channel}");
        }

        try {
            $result = $this->channels[$channel]->send($recipient, $data);
            
            $this->logDelivery($channel, $recipient, $data, $result);
            
            return $result;

        } catch (\Exception $e) {
            Log::error("Failed to send notification through {$channel}", [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    protected function logDelivery(string $channel, $recipient, array $data, bool $result): void
    {
        Log::info("Notification sent through {$channel}", [
            'recipient' => $recipient,
            'success' => $result,
            'timestamp' => now()
        ]);
    }
}

// app/Core/Notification/Channels/EmailChannel.php
<?php

namespace App\Core\Notification\Channels;

use Illuminate\Support\Facades\Mail;
use App\Core\Mail\NotificationMailer;

class EmailChannel implements NotificationChannel
{
    private NotificationMailer $mailer;
    
    public function __construct(NotificationMailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function send($recipient, array $data): bool
    {
        try {
            $this->validateEmailData($data);

            $this->mailer->send(
                'notifications.email.default',
                $data,
                function($message) use ($recipient, $data) {
                    $message->to($recipient)
                            ->subject($data['title']);
                }
            );

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send email notification', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    protected function validateEmailData(array $data): void
    {
        $required = ['title', 'content'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }
}

// app/Core/Notification/Channels/SlackChannel.php
<?php

namespace App\Core\Notification\Channels;

use Illuminate\Support\Facades\Http;

class SlackChannel implements NotificationChannel  
{
    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function send($recipient, array $data): bool
    {
        try {
            $payload = $this->buildSlackPayload($data);

            $response = Http::post($this->webhookUrl, $payload);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Failed to send Slack notification', [
                'webhook_url' => $this->webhookUrl,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    protected function buildSlackPayload(array $data): array
    {
        return [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*{$data['title']}*\n\n{$data['content']}"
                    ]
                ]
            ]
        ];
    }
}

// app/Core/Notification/Channels/DatabaseChannel.php
<?php

namespace App\Core\Notification\Channels;

use App\Core\Notification\Repositories\NotificationRepository;

class DatabaseChannel implements NotificationChannel
{
    private NotificationRepository $repository;

    public function __construct(NotificationRepository $repository) 
    {
        $this->repository = $repository;
    }

    public function send($recipient, array $data): bool
    {
        try {
            $this->repository->create([
                'recipient_id' => $recipient,
                'title' => $data['title'],
                'content' => $data['content'],
                'read_at' => null,
                'data' => json_encode($data)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to save notification to database', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}