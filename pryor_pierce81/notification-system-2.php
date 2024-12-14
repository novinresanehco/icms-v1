<?php

namespace App\Core\Monitoring\Notifications;

class NotificationManager {
    private NotificationFactory $factory;
    private ChannelManager $channelManager;
    private RateLimiter $rateLimiter;
    private NotificationStore $store;
    private TemplateEngine $templateEngine;

    public function __construct(
        NotificationFactory $factory,
        ChannelManager $channelManager,
        RateLimiter $rateLimiter,
        NotificationStore $store,
        TemplateEngine $templateEngine
    ) {
        $this->factory = $factory;
        $this->channelManager = $channelManager;
        $this->rateLimiter = $rateLimiter;
        $this->store = $store;
        $this->templateEngine = $templateEngine;
    }

    public function notify(NotificationRequest $request): NotificationResult 
    {
        $notification = $this->factory->create($request);
        
        if (!$this->rateLimiter->canSend($notification)) {
            return new NotificationResult(false, 'Rate limit exceeded');
        }

        $channels = $this->channelManager->getChannels($notification);
        $results = [];

        foreach ($channels as $channel) {
            $content = $this->templateEngine->render($notification, $channel);
            $results[$channel->getName()] = $channel->send($notification, $content);
        }

        $this->store->store($notification, $results);

        return new NotificationResult(true, 'Notification sent', $results);
    }
}

class NotificationFactory {
    private array $templates;
    private array $defaults;

    public function create(NotificationRequest $request): Notification 
    {
        $template = $this->templates[$request->getType()] ?? null;
        if (!$template) {
            throw new \InvalidArgumentException("Unknown notification type: {$request->getType()}");
        }

        return new Notification(
            $request->getType(),
            $request->getRecipients(),
            array_merge($this->defaults, $request->getData()),
            $template
        );
    }
}

class ChannelManager {
    private array $channels;
    private PreferenceManager $preferenceManager;
    private array $routingRules;

    public function getChannels(Notification $notification): array 
    {
        $availableChannels = [];

        foreach ($this->channels as $channel) {
            if ($this->shouldUseChannel($channel, $notification)) {
                $availableChannels[] = $channel;
            }
        }

        return $this->prioritizeChannels($availableChannels, $notification);
    }

    private function shouldUseChannel(Channel $channel, Notification $notification): bool 
    {
        if (!$channel->isAvailable()) {
            return false;
        }

        foreach ($this->routingRules as $rule) {
            if (!$rule->allows($channel, $notification)) {
                return false;
            }
        }

        return $this->preferenceManager->isChannelEnabled($channel, $notification);
    }

    private function prioritizeChannels(array $channels, Notification $notification): array 
    {
        usort($channels, function($a, $b) use ($notification) {
            return $b->getPriority($notification) - $a->getPriority($notification);
        });

        return $channels;
    }
}

class RateLimiter {
    private CacheInterface $cache;
    private array $limits;

    public function canSend(Notification $notification): bool 
    {
        $key = $this->generateKey($notification);
        $count = (int)$this->cache->get($key, 0);

        if ($count >= $this->getLimit($notification)) {
            return false;
        }

        $this->cache->increment($key);
        return true;
    }

    private function generateKey(Notification $notification): string 
    {
        return sprintf(
            'notification_limit:%s:%s:%s',
            $notification->getType(),
            implode(',', $notification->getRecipients()),
            date('Y-m-d')
        );
    }

    private function getLimit(Notification $notification): int 
    {
        return $this->limits[$notification->getType()] ?? 
               $this->limits['default'] ?? 
               100;
    }
}

class NotificationStore {
    private DatabaseConnection $db;
    private array $listeners = [];

    public function store(Notification $notification, array $results): void 
    {
        $record = $this->createRecord($notification, $results);
        $this->db->insert('notifications', $record);

        foreach ($this->listeners as $listener) {
            $listener->onNotificationStored($notification, $results);
        }
    }

    private function createRecord(Notification $notification, array $results): array 
    {
        return [
            'type' => $notification->getType(),
            'recipients' => json_encode($notification->getRecipients()),
            'data' => json_encode($notification->getData()),
            'results' => json_encode($results),
            'created_at' => date('Y-m-d H:i:s'),
            'status' => $this->determineStatus($results)
        ];
    }

    private function determineStatus(array $results): string 
    {
        $allSuccess = true;
        $allFailure = true;

        foreach ($results as $result) {
            if ($result->isSuccess()) {
                $allFailure = false;
            } else {
                $allSuccess = false;
            }
        }

        if ($allSuccess) return 'success';
        if ($allFailure) return 'failure';
        return 'partial';
    }
}

class TemplateEngine {
    private array $templates;
    private ContentFormatter $formatter;
    private array $filters;

    public function render(Notification $notification, Channel $channel): string 
    {
        $template = $this->getTemplate($notification, $channel);
        $content = $this->renderTemplate($template, $notification->getData());
        
        $content = $this->applyFilters($content, $channel);
        return $this->formatter->format($content, $channel->getFormat());
    }

    private function getTemplate(Notification $notification, Channel $channel): Template 
    {
        $key = sprintf('%s.%s', $notification->getType(), $channel->getName());
        
        if (!isset($this->templates[$key])) {
            throw new \RuntimeException("Template not found: {$key}");
        }

        return $this->templates[$key];
    }

    private function renderTemplate(Template $template, array $data): string 
    {
        return $template->render($data);
    }

    private function applyFilters(string $content, Channel $channel): string 
    {
        foreach ($this->filters as $filter) {
            if ($filter->appliesToChannel($channel)) {
                $content = $filter->apply($content);
            }
        }

        return $content;
    }
}

class Notification {
    private string $id;
    private string $type;
    private array $recipients;
    private array $data;
    private Template $template;
    private float $timestamp;

    public function __construct(string $type, array $recipients, array $data, Template $template) 
    {
        $this->id = uniqid('notif_', true);
        $this->type = $type;
        $this->recipients = $recipients;
        $this->data = $data;
        $this->template = $template;
        $this->timestamp = microtime(true);
    }

    public function getId(): string 
    {
        return $this->id;
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getRecipients(): array 
    {
        return $this->recipients;
    }

    public function getData(): array 
    {
        return $this->data;
    }

    public function getTemplate(): Template 
    {
        return $this->template;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }
}

class NotificationResult {
    private bool $success;
    private string $message;
    private array $channelResults;

    public function __construct(bool $success, string $message, array $channelResults = []) 
    {
        $this->success = $success;
        $this->message = $message;
        $this->channelResults = $channelResults;
    }

    public function isSuccess(): bool 
    {
        return $this->success;
    }

    public function getMessage(): string 
    {
        return $this->message;
    }

    public function getChannelResults(): array 
    {
        return $this->channelResults;
    }

    public function toArray(): array 
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'channel_results' => $this->channelResults
        ];
    }
}

