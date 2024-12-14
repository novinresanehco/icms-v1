<?php

namespace App\Core\Monitoring;

class NotificationService implements NotificationServiceInterface
{
    protected function logCriticalSuccess(array $notification, string $operationId): void
    {
        $this->logger->logCritical([
            'type' => 'critical_notification_sent',
            'notification' => $notification,
            'operation_id' => $operationId,
            'severity' => 'CRITICAL',
            'timestamp' => time()
        ]);
    }

    protected function handleFailure(\Throwable $e, array $notification, string $operationId): void
    {
        $this->logger->logFailure([
            'type' => 'notification_failure',
            'error' => $e->getMessage(),
            'notification' => $notification,
            'operation_id' => $operationId,
            'severity' => 'ERROR'
        ]);

        $this->store->storeFailure([
            'error' => $e->getMessage(),
            'notification' => $notification,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);
    }

    protected function handleCriticalFailure(\Throwable $e, array $notification, string $operationId): void
    {
        $this->logger->logCritical([
            'type' => 'critical_notification_failure',
            'error' => $e->getMessage(),
            'notification' => $notification,
            'operation_id' => $operationId,
            'severity' => 'CRITICAL'
        ]);

        $this->store->storeCriticalFailure([
            'error' => $e->getMessage(),
            'notification' => $notification,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);

        $this->triggerEscalation($e, $notification, $operationId);
    }

    protected function triggerEscalation(\Throwable $e, array $notification, string $operationId): void
    {
        $escalation = [
            'type' => 'notification_escalation',
            'error' => $e->getMessage(),
            'notification' => $notification,
            'operation_id' => $operationId,
            'timestamp' => time(),
            'severity' => 'CRITICAL'
        ];

        foreach ($this->getEscalationChannels() as $channel) {
            try {
                $channel->escalate($escalation);
            } catch (\Throwable $channelError) {
                $this->logger->logCritical([
                    'type' => 'escalation_channel_failure',
                    'channel' => get_class($channel),
                    'error' => $channelError->getMessage(),
                    'escalation' => $escalation
                ]);
            }
        }
    }

    protected function getEscalationChannels(): array
    {
        return array_filter($this->channels, function($channel) {
            return $channel instanceof EscalationChannelInterface;
        });
    }
}

interface NotificationChannelInterface {
    public function send(array $notification): void;
    public function sendUrgent(array $notification): void;
}

interface EscalationChannelInterface extends NotificationChannelInterface {
    public function escalate(array $escalation): void;
}

class NotificationFormatter {
    public function format(array $notification): array {
        // Basic notification formatting
        return array_merge($notification, [
            'formatted_message' => $this->formatMessage($notification['message']),
            'formatted_timestamp' => $this->formatTimestamp($notification['timestamp'])
        ]);
    }

    public function formatCritical(array $notification): array {
        // Enhanced formatting for critical notifications
        return array_merge($this->format($notification), [
            'formatted_severity' => $this->formatCriticalSeverity(),
            'formatted_priority' => $this->formatCriticalPriority(),
            'escalation_info' => $this->formatEscalationInfo($notification)
        ]);
    }

    protected function formatMessage(string $message): string {
        // Message formatting logic
        return trim($message);
    }

    protected function formatTimestamp(int $timestamp): string {
        // Timestamp formatting logic
        return date('Y-m-d H:i:s', $timestamp);
    }

    protected function formatCriticalSeverity(): string {
        return '!!! CRITICAL !!!';
    }

    protected function formatCriticalPriority(): string {
        return 'IMMEDIATE ACTION REQUIRED';
    }

    protected function formatEscalationInfo(array $notification): array {
        return [
            'escalation_level' => 'HIGHEST',
            'response_required' => true,
            'escalation_timestamp' => $this->formatTimestamp(time())
        ];
    }
}

class NotificationStore {
    private StorageInterface $storage;
    private CacheManager $cache;
    private array $config;

    public function store(array $data): void {
        $this->validateStorageData($data);
        $this->storeWithRetry($data);
        $this->updateCache($data);
    }

    public function storeCritical(array $data): void {
        $this->validateCriticalStorageData($data);
        $this->storeWithHighPriority($data);
        $this->updateCriticalCache($data);
        $this->ensureStorageSuccess($data);
    }

    protected function storeWithRetry(array $data, int $retries = 3): void {
        $attempt = 0;
        do {
            try {
                $this->storage->store($data);
                return;
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw $e;
                }
                usleep(100000 * $attempt); // Exponential backoff
            }
        } while (true);
    }

    protected function storeWithHighPriority(array $data): void {
        $this->storage->storeHighPriority($data);
    }

    protected function ensureStorageSuccess(array $data): void {
        if (!$this->storage->verify($data)) {
            throw new StorageException('Critical storage verification failed');
        }
    }
}
