<?php

namespace App\Core\Broadcasting;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use App\Core\Exceptions\BroadcastException;
use Exception;

class EventBroadcastingSystem
{
    protected ChannelManager $channelManager;
    protected ConnectionPool $connectionPool;
    protected EventSerializer $serializer;
    protected BroadcastMetrics $metrics;
    protected array $config;

    public function __construct(
        ChannelManager $channelManager,
        ConnectionPool $connectionPool,
        EventSerializer $serializer,
        BroadcastMetrics $metrics
    ) {
        $this->channelManager = $channelManager;
        $this->connectionPool = $connectionPool;
        $this->serializer = $serializer;
        $this->metrics = $metrics;
        
        $this->config = config('broadcasting');
    }

    public function broadcast(BroadcastEvent $event): BroadcastResult
    {
        $startTime = microtime(true);

        try {
            // Start broadcast transaction
            $transaction = $this->beginBroadcastTransaction();

            try {
                // Prepare event for broadcasting
                $preparedEvent = $this->prepareEvent($event);

                // Get target channels
                $channels = $this->channelManager->getTargetChannels($event);

                // Validate channels
                $this->validateChannels($channels);

                // Broadcast to each channel
                $results = $this->broadcastToChannels($channels, $preparedEvent);

                // Commit transaction
                $transaction->commit();

                // Record metrics
                $this->recordBroadcastMetrics($event, $channels, microtime(true) - $startTime);

                return new BroadcastResult($results);

            } catch (Exception $e) {
                $transaction->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            $this->handleBroadcastError($e, $event);
            throw new BroadcastException(
                "Broadcasting failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function prepareEvent(BroadcastEvent $event): PreparedEvent
    {
        return new PreparedEvent(
            $this->serializer->serialize($event),
            [
                'id' => uniqid('event_', true),
                'type' => get_class($event),
                'timestamp' => microtime(true),
                'metadata' => $event->getMetadata()
            ]
        );
    }

    protected function broadcastToChannels(array $channels, PreparedEvent $event): array
    {
        $results = [];

        foreach ($channels as $channel) {
            try {
                $connections = $this->connectionPool->getActiveConnections($channel);
                
                if (empty($connections)) {
                    continue;
                }

                $batchSize = $this->config['batch_size'] ?? 100;
                $connectionBatches = array_chunk($connections, $batchSize);

                foreach ($connectionBatches as $batch) {
                    $results[] = $this->processBatch($batch, $event, $channel);
                }

            } catch (Exception $e) {
                Log::error("Failed to broadcast to channel {$channel->getName()}", [
                    'error' => $e->getMessage(),
                    'event' => get_class($event)
                ]);

                $results[] = new ChannelBroadcastResult($channel, false, $e->getMessage());
            }
        }

        return $results;
    }

    protected function processBatch(array $connections, PreparedEvent $event, Channel $channel): ChannelBroadcastResult
    {
        $failedConnections = [];

        foreach ($connections as $connection) {
            try {
                if (!$this->sendToConnection($connection, $event)) {
                    $failedConnections[] = $connection->getId();
                }
            } catch (Exception $e) {
                $failedConnections[] = $connection->getId();
                Log::warning("Failed to send to connection {$connection->getId()}", [
                    'error' => $e->getMessage(),
                    'channel' => $channel->getName()
                ]);
            }
        }

        $success = empty($failedConnections);
        $message = $success ? 'Broadcast successful' : 'Some connections failed';

        return new ChannelBroadcastResult($channel, $success, $message, $failedConnections);
    }

    protected function sendToConnection(Connection $connection, PreparedEvent $event): bool
    {
        $maxRetries = $this->config['max_retries'] ?? 3;
        $retryDelay = $this->config['retry_delay'] ?? 100; // milliseconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $connection->send($event->getData());
                return true;
            } catch (Exception $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }
                usleep($retryDelay * 1000); // Convert to microseconds
            }
        }

        return false;
    }

    protected function validateChannels(array $channels): void
    {
        foreach ($channels as $channel) {
            if (!$channel->isValid()) {
                throw new BroadcastException("Invalid channel: {$channel->getName()}");
            }
        }
    }

    protected function recordBroadcastMetrics(BroadcastEvent $event, array $channels, float $duration): void
    {
        $this->metrics->record([
            'event_type' => get_class($event),
            'channels_count' => count($channels),
            'duration' => $duration,
            'timestamp' => microtime(true)
        ]);
    }

    protected function handleBroadcastError(Exception $e, BroadcastEvent $event): void
    {
        Log::error('Broadcasting failed', [
            'event' => get_class($event),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordError(get_class($event), $e->getMessage());
    }
}

class ChannelManager
{
    protected Collection $channels;
    protected array $config;

    public function __construct()
    {
        $this->channels = new Collection();
        $this->config = config('broadcasting.channels', []);
    }

    public function registerChannel(Channel $channel): void
    {
        $this->channels->put($channel->getName(), $channel);
    }

    public function getChannel(string $name): ?Channel
    {
        return $this->channels->get($name);
    }

    public function getTargetChannels(BroadcastEvent $event): array
    {
        $targetChannels = [];

        foreach ($event->getChannels() as $channelName) {
            $channel = $this->getChannel($channelName);
            if ($channel && $channel->canBroadcast($event)) {
                $targetChannels[] = $channel;
            }
        }

        return $targetChannels;
    }
}

class ConnectionPool
{
    protected Collection $connections;
    protected array $connectionsByChannel;

    public function __construct()
    {
        $this->connections = new Collection();
        $this->connectionsByChannel = [];
    }

    public function addConnection(Connection $connection): void
    {
        $this->connections->put($connection->getId(), $connection);
    }

    public function removeConnection(string $connectionId): void
    {
        $this->connections->forget($connectionId);
    }

    public function getActiveConnections(Channel $channel): array
    {
        return array_filter(
            $this->connections->all(),
            fn($connection) => $connection->isSubscribedTo($channel) && $connection->isActive()
        );
    }
}

class Channel
{
    protected string $name;
    protected string $type;
    protected array $options;
    protected bool $active = true;

    public function __construct(string $name, string $type, array $options = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->options = $options;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isValid(): bool
    {
        return $this->active && $this->validateOptions();
    }

    public function canBroadcast(BroadcastEvent $event): bool
    {
        return $this->isValid() && $this->checkEventPermissions($event);
    }

    protected function validateOptions(): bool
    {
        // Implement channel-specific validation logic
        return true;
    }

    protected function checkEventPermissions(BroadcastEvent $event): bool
    {
        // Implement event permission checking logic
        return true;
    }
}

class Connection
{
    protected string $id;
    protected array $subscribedChannels = [];
    protected bool $active = true;
    protected $socket;

    public function __construct(string $id, $socket)
    {
        $this->id = $id;
        $this->socket = $socket;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function subscribe(Channel $channel): void
    {
        $this->subscribedChannels[] = $channel->getName();
    }

    public function unsubscribe(Channel $channel): void
    {
        $key = array_search($channel->getName(), $this->subscribedChannels);
        if ($key !== false) {
            unset($this->subscribedChannels[$key]);
        }
    }

    public function isSubscribedTo(Channel $channel): bool
    {
        return in_array($channel->getName(), $this->subscribedChannels);
    }

    public function send($data): bool
    {
        // Implement actual sending logic using $this->socket
        return true;
    }
}

class BroadcastResult
{
    protected array $channelResults;

    public function __construct(array $channelResults)
    {
        $this->channelResults = $channelResults;
    }

    public function isSuccessful(): bool
    {
        return collect($this->channelResults)->every(fn($result) => $result->isSuccessful());
    }

    public function getFailedChannels(): array
    {
        return collect($this->channelResults)
            ->filter(fn($result) => !$result->isSuccessful())
            ->map(fn($result) => $result->getChannel()->getName())
            ->values()
            ->all();
    }
}

class ChannelBroadcastResult
{
    protected Channel $channel;
    protected bool $success;
    protected string $message;
    protected array $failedConnections;

    public function __construct(Channel $channel, bool $success, string $message, array $failedConnections = [])
    {
        $this->channel = $channel;
        $this->success = $success;
        $this->message = $message;
        $this->failedConnections = $failedConnections;
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }
}

class BroadcastMetrics
{
    protected array $metrics = [];

    public function record(array $data): void
    {
        $this->metrics[] = $data;
    }

    public function recordError(string $eventType, string $error): void
    {
        $this->metrics[] = [
            'type' => 'error',
            'event_type' => $eventType,
            'error' => $error,
            'timestamp' => microtime(true)
        ];
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
