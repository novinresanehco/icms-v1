<?php

namespace App\Core\Events\Broadcasting;

class EventBroadcaster
{
    private array $channels = [];
    private ChannelRegistry $registry;
    private BroadcastingMetrics $metrics;

    public function __construct(ChannelRegistry $registry, BroadcastingMetrics $metrics)
    {
        $this->registry = $registry;
        $this->metrics = $metrics;
    }

    public function broadcast(Event $event, array $channels): void
    {
        foreach ($channels as $channelName) {
            $channel = $this->registry->getChannel($channelName);
            
            try {
                $channel->broadcast($event);
                $this->metrics->recordBroadcast($channelName);
            } catch (\Exception $e) {
                $this->handleBroadcastError($e, $event, $channelName);
            }
        }
    }

    private function handleBroadcastError(\Exception $e, Event $event, string $channel): void
    {
        $this->metrics->recordError($channel);
        throw new BroadcastException(
            "Failed to broadcast event to channel {$channel}: {$e->getMessage()}",
            0,
            $e
        );
    }
}

interface Channel
{
    public function broadcast(Event $event): void;
    public function subscribe(Subscriber $subscriber): void;
    public function unsubscribe(Subscriber $subscriber): void;
}

class WebSocketChannel implements Channel
{
    private array $subscribers = [];
    private WebSocketServer $server;

    public function __construct(WebSocketServer $server)
    {
        $this->server = $server;
    }

    public function broadcast(Event $event): void
    {
        $payload = json_encode([
            'event' => $event->getName(),
            'data' => $event->getData(),
            'time' => $event->getTime()
        ]);

        foreach ($this->subscribers as $subscriber) {
            $this->server->send($subscriber->getId(), $payload);
        }
    }

    public function subscribe(Subscriber $subscriber): void
    {
        $this->subscribers[$subscriber->getId()] = $subscriber;
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        unset($this->subscribers[$subscriber->getId()]);
    }
}

class RedisChannel implements Channel
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis, string $prefix = 'broadcast:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function broadcast(Event $event): void
    {
        $payload = json_encode([
            'event' => $event->getName(),
            'data' => $event->getData(),
            'time' => $event->getTime()
        ]);

        $this->redis->publish(
            $this->prefix . $event->getName(),
            $payload
        );
    }

    public function subscribe(Subscriber $subscriber): void
    {
        // Redis PubSub subscription handling
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        // Redis PubSub unsubscription handling
    }
}
