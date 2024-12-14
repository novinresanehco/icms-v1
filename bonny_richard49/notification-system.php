// app/Core/Notification/NotificationManager.php
<?php

namespace App\Core\Notification;

use App\Core\Notification\Channel\ChannelManager;
use App\Core\Notification\Template\TemplateRenderer;
use App\Core\Notification\Queue\QueueManager;

class NotificationManager
{
    private ChannelManager $channelManager;
    private TemplateRenderer $templateRenderer;
    private QueueManager $queueManager;

    public function __construct(
        ChannelManager $channelManager,
        TemplateRenderer $templateRenderer,
        QueueManager $queueManager
    ) {
        $this->channelManager = $channelManager;
        $this->templateRenderer = $templateRenderer;
        $this->queueManager = $queueManager;
    }

    public function send(string $recipient, string $template, array $data = [], array $channels = []): void
    {
        $content = $this->templateRenderer->render($template, $data);
        
        if (empty($channels)) {
            $channels = $this->channelManager->getDefaultChannels();
        }

        foreach ($channels as $channel) {
            $this->queueManager->push([
                'channel' => $channel,
                'recipient' => $recipient,
                'content' => $content,
                'data' => $data
            ]);
        }
    }

    public function sendNow(string $recipient, string $template, array $data = [], array $channels = []): void
    {
        $content = $this->templateRenderer->render($template, $data);

        if (empty($channels)) {
            $channels = $this->channelManager->getDefaultChannels();
        }

        foreach ($channels as $channel) {
            $this->channelManager->get($channel)->send($recipient, $content, $data);
        }
    }
}

// app/Core/Notification/Channel/ChannelManager.php
<?php

namespace App\Core\Notification\Channel;

class ChannelManager
{
    private array $channels = [];
    private array $defaultChannels = [];

    public function register(string $name, ChannelInterface $channel): void
    {
        $this->channels[$name] = $channel;
    }

    public function get(string $name): ChannelInterface
    {
        if (!isset($this->channels[$name])) {
            throw new \InvalidArgumentException("Channel {$name} not found");
        }

        return $this->channels[$name];
    }

    public function setDefaultChannels(array $channels): void
    {
        $this->defaultChannels = $channels;
    }

    public function getDefaultChannels(): array
    {
        return $this->defaultChannels;
    }
}

// app/Core/Notification/Channel/EmailChannel.php
<?php

namespace App\Core\Notification\Channel;

use Illuminate\Support\Facades\Mail;

class EmailChannel implements ChannelInterface
{
    public function send(string $recipient, string $content, array $data = []): void
    {
        Mail::raw($content, function($message) use ($recipient, $data) {
            $message->to($recipient)
                   ->subject($data['subject'] ?? 'Notification');
        });
    }
}

// app/Core/Notification/Channel/SmsChannel.php
<?php

namespace App\Core\Notification\Channel;

class SmsChannel implements ChannelInterface
{
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function send(string $recipient, string $content, array $data = []): void
    {
        $this->client->messages->create($recipient, [
            'from' => config('services.sms.from'),
            'body' => $content
        ]);
    }
}

// app/Core/Notification/Channel/SlackChannel.php
<?php

namespace App\Core\Notification\Channel;

use Illuminate\Support\Facades\Http;

class SlackChannel implements ChannelInterface
{
    private string $webhook;

    public function __construct(string $webhook)
    {
        $this->webhook = $webhook;
    }

    public function send(string $recipient, string $content, array $data = []): void
    {
        Http::post($this->webhook, [
            'channel' => $recipient,
            'text' => $content,
            'attachments' => $data['attachments'] ?? []
        ]);
    }
}

// app/Core/Notification/Channel/ChannelInterface.php
<?php

namespace App\Core\Notification\Channel;

interface ChannelInterface
{
    public function send(string $recipient, string $content, array $data = []): void;
}

// app/Core/Notification/Template/TemplateRenderer.php
<?php

namespace App\Core\Notification\Template;

use Illuminate\Support\Facades\View;

class TemplateRenderer
{
    public function render(string $template, array $data = []): string
    {
        if (View::exists($template)) {
            return View::make($template, $data)->render();
        }

        return $template;
    }
}

// app/Core/Notification/Queue/QueueManager.php
<?php

namespace App\Core\Notification\Queue;

use Illuminate\Support\Facades\Queue;

class QueueManager
{
    private string $queue;

    public function __construct(string $queue = 'notifications')
    {
        $this->queue = $queue;
    }

    public function push(array $notification): void
    {
        Queue::push(new SendNotificationJob($notification));
    }
}

// app/Core/Notification/Queue/SendNotificationJob.php
<?php

namespace App\Core\Notification\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Core\Notification\Channel\ChannelManager;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $notification;

    public function __construct(array $notification)
    {
        $this->notification = $notification;
    }

    public function handle(ChannelManager $channels): void
    {
        $channel = $channels->get($this->notification['channel']);
        $channel->send(
            $this->notification['recipient'],
            $this->notification['content'],
            $this->notification['data']
        );
    }
}

// app/Core/Notification/NotificationServiceProvider.php
<?php

namespace App\Core\Notification;

use Illuminate\Support\ServiceProvider;
use App\Core\Notification\Channel\ChannelManager;
use App\Core\Notification\Channel\EmailChannel;
use App\Core\Notification\Channel\SmsChannel;
use App\Core\Notification\Channel\SlackChannel;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelManager::class, function ($app) {
            $manager = new ChannelManager();

            $manager->register('email', new EmailChannel());
            $manager->register('sms', new SmsChannel($app->make('sms.client')));
            $manager->register('slack', new SlackChannel(config('services.slack.webhook')));

            $manager->setDefaultChannels(['email']);

            return $manager;
        });

        $this->app->singleton(NotificationManager::class, function ($app) {
            return new NotificationManager(
                $app->make(ChannelManager::class),
                new Template\TemplateRenderer(),
                new Queue\QueueManager()
            );
        });
    }
}