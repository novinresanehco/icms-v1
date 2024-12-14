<?php

namespace App\Core\Notification\Analytics\Queue;

class AnalyticsQueue
{
    private array $queues = [];
    private array $processors = [];
    private array $metrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_size' => 1000,
            'batch_size' => 100,
            'retry_attempts' => 3,
            'retry_delay' => 60
        ], $config);
    }

    public function registerQueue(string $name, array $config = []): void
    {
        $this->queues[$name] = [
            'items' => [],
            'config' => array_merge($this->config, $config),
            'stats' => [
                'enqueued' => 0,
                'processed' => 0,
                'failed' => 0
            ]
        ];
    }

    public function registerProcessor(string $queueName, callable $processor): void
    {
        $this->processors[$queueName] = $processor;
    }

    public function enqueue(string $queueName, $item): bool
    {
        if (!isset($this->queues[$queueName])) {
            throw new \InvalidArgumentException("Queue not found: {$queueName}");
        }

        $queue = &$this->queues[$queueName];
        if (count($queue['items']) >= $queue['config']['max_size']) {
            return false;
        }

        $queue['items'][] = [
            'data' => $item,
            'attempts' => 0,
            'enqueued_at' => microtime(true)
        ];
        $queue['stats']['enqueued']++;

        $this->updateMetrics($queueName, 'enqueue');
        return true;
    }

    public function process(string $queueName): array
    {
        if (!isset($this->queues[$queueName])) {
            throw new \InvalidArgumentException("Queue not found: {$queueName}");
        }

        if (!isset($this->processors[$queueName])) {
            throw new \RuntimeException("No processor registered for queue: {$queueName}");
        }

        $queue = &$this->queues[$queueName];
        $batchSize = $queue['config']['batch_size'];
        $results = [];

        while (!empty($queue['items']) && count($results) < $batchSize) {
            $item = array_shift($queue['items']);
            try {
                $result = ($this->processors[$queueName])($item['data']);
                $this->handleSuccess($queueName, $item, $result);
                $results[] = $result;
            } catch (\Exception $e) {
                $this->handleFailure($queueName, $item, $e);
            }
        }

        return $results;
    }

    public function getStats(string $queueName): array
    {
        if (!isset($this->queues[$queueName])) {
            throw new \InvalidArgumentException("Queue not found: {$queueName}");
        }

        return [
            'stats' => $this->queues[$queueName]['stats'],
            'current_size' => count($this->queues[$queueName]['items']),
            'metrics' => $this->metrics[$queueName] ?? []
        ];
    }

    private function handleSuccess(string $queueName, array $item, $result): void
    {
        $this->queues[$queueName]['stats']['processed']++;
        $this->updateMetrics($queueName, 'process_success', [
            'processing_time' => microtime(true) - $item['enqueued_at']
        ]);
    }

    private function handleFailure(string $queueName, array $item, \Exception $error): void
    {
        $queue = &$this->queues[$queueName];
        $item['attempts']++;
        
        if ($item['attempts'] < $queue['config']['retry_attempts']) {
            $item['retry_after'] = time() + ($queue['config']['retry_delay'] * $item['attempts']);
            array_push($queue['items'], $item);
        } else {
            $queue['stats']['failed']++;
            $this->updateMetrics($queueName, 'process_failure', [
                'error' => $error->getMessage(),
                'attempts' => $item['attempts']
            ]);
        }
    }

    private function updateMetrics(string $queueName, string $operation, array $data = []): void
    {
        if (!isset($this->metrics[$queueName])) {
            $this->metrics[$queueName] = [
                'operations' => [],
                'timings' => []
            ];
        }

        if (!isset($this->metrics[$queueName]['operations'][$operation])) {
            $this->metrics[$queueName]['operations'][$operation] = 0;
        }

        $this->metrics[$queueName]['operations'][$operation]++;

        if (isset($data['processing_time'])) {
            $this->metrics[$queueName]['timings'][] = $data['processing_time'];
        }
    }
}
