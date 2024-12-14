<?php

namespace App\Core\Events\Subscribers;

interface Subscriber
{
    public function getId(): string;
    public function getSubscriptions(): array;
    public function notify(Event $event): void;
}

class WebSocketSubscriber implements Subscriber
{
    private string $id;
    private array $subscriptions;
    private WebSocketConnection $connection;

    public function __construct(string $id, WebSocketConnection $connection)
    {
        $this->id = $id;
        $this->connection = $connection;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSubscriptions(): array
    {
        return $this->subscriptions;
    }

    public function notify(Event $event): void
    {
        $this->connection->send(json_encode([
            'type' => 'event',
            'event' => $event->getName(),
            'data' => $event->getData()
        ]));
    }

    public function subscribe(string $channel): void
    {
        $this->subscriptions[] = $channel;
    }

    public function unsubscribe(string $channel): void
    {
        $key = array_search($channel, $this->subscriptions);
        if ($key !== false) {
            unset($this->subscriptions[$key]);
        }
    }
}

class EventSubscriptionManager
{
    private array $subscribers = [];
    private SubscriptionValidator $validator;
    private SubscriptionMetrics $metrics;

    public function __construct(
        SubscriptionValidator $validator,
        SubscriptionMetrics $metrics
    ) {
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function subscribe(Subscriber $subscriber, array $channels): void
    {
        foreach ($channels as $channel) {
            $this->validator->validateSubscription($subscriber, $channel);
            $this->addSubscription($subscriber, $channel);
            $this->metrics->recordSubscription($channel);
        }
    }

    public function unsubscribe(Subscriber $subscriber, array $channels): void
    {
        foreach ($channels as $channel) {
            $this->removeSubscription($subscriber, $channel);
            $this->metrics->recordUnsubscription($channel);
        }
    }

    private function addSubscription(Subscriber $subscriber, string $channel): void
    {
        if (!isset($this->subscribers[$channel])) {
            $this->subscribers[$channel] = [];
        }
        $this->subscribers[$channel][$subscriber->getId()] = $subscriber;
    }

    private function removeSubscription(Subscriber $subscriber, string $channel): void
    {
        unset($this->subscribers[$channel][$subscriber->getId()]);
        if (empty($this->subscribers[$channel])) {
            unset($this->subscribers[$channel]);
        }
    }
}

class SubscriptionValidator
{
    public function validateSubscription(Subscriber $subscriber, string $channel): void
    {
        if (empty($subscriber->getId())) {
            throw new SubscriptionException('Invalid subscriber ID');
        }

        if (empty($channel)) {
            throw new SubscriptionException('Invalid channel name');
        }

        // Additional validation rules can be added here
    }
}
