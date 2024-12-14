<?php

namespace App\Core\Audit\Queues;

class AnalysisQueue
{
    private QueueAdapter $adapter;
    private EventDispatcher $dispatcher;
    private array $config;

    public function __construct(
        QueueAdapter $adapter,
        EventDispatcher $dispatcher,
        array $config = []
    ) {
        $this->adapter = $adapter;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
    }

    public function push(AnalysisRequest $request): string
    {
        $job = new AnalysisJob(
            $request,
            $this->config['default_priority'] ?? 0
        );

        $id = $this->adapter->push($job);

        $this->dispatcher->dispatch(
            new JobQueuedEvent($id, $job)
        );

        return $id;
    }

    public function pushBatch(array $requests): string
    {
        $batch = new AnalysisBatch();

        foreach ($requests as $request) {
            $job = new AnalysisJob(
                $request,
                $this->config['default_priority'] ?? 0
            );
            $batch->addJob($job);
        }

        $id = $this->adapter->pushBatch($batch);

        $this->dispatcher->dispatch(
            new BatchQueuedEvent($id, $batch)
        );

        return $id;
    }

    public function pop(): ?AnalysisJob
    {
        $job = $this->adapter->pop();

        if ($job) {
            $this->dispatcher->dispatch(
                new JobDequeuedEvent($job->getId(), $job)
            );
        }

        return $job;
    }

    public function complete(string $id): void
    {
        $this->adapter->complete($id);

        $this->dispatcher->dispatch(
            new JobCompletedEvent($id)
        );
    }

    public function fail(string $id, string $error): void
    {
        $this->adapter->fail($id, $error);

        $this->dispatcher->dispatch(
            new JobFailedEvent($id, $error)
        );
    }

    public function retry(string $id, int $delay = 0): void
    {
        $this->adapter->retry($id, $delay);

        $this->dispatcher->dispatch(
            new JobRetriedEvent($id, $delay)
        );
    }
}

class QueueManager
{
    private array $queues;
    private LoggerInterface $logger;

    public function __construct(array $queues, LoggerInterface $logger)
    {
        $this->queues = $queues;
        $this->logger = $logger;
    }

    public function getQueue(string $name): AnalysisQueue
    {
        if (!isset($this->queues[$name])) {
            throw new \InvalidArgumentException("Queue not found: {$name}");
        }

        return $this->queues[$name];
    }

    public function pushToQueue(string $name, AnalysisRequest $request): string
    {
        $queue = $this->getQueue($name);
        return $queue->push($request);
    }

    public function pushToBestQueue(AnalysisRequest $request): string
    {
        $queue = $this->selectQueue($request);
        return $queue->push($request);
    }

    private function selectQueue(AnalysisRequest $request): AnalysisQueue
    {
        // Select best queue based on request characteristics
        $metrics = [
            'size' => $this->calculateRequestSize($request),
            'complexity' => $this->estimateComplexity($request),
            'priority' => $request->getPriority()
        ];

        return $this->findOptimalQueue($metrics);
    }

    private function calculateRequestSize(AnalysisRequest $request): int
    {
        return strlen(serialize($request->getData()));
    }

    private function estimateComplexity(AnalysisRequest $request): float
    {
        $config = $request->getConfig();
        
        $score = 0;
        if (!empty($config['statistical'])) $score += 1;
        if (!empty($config['pattern'])) $score += 2;
        if (!empty($config['anomaly'])) $score += 3;
        
        return $score;
    }

    private function findOptimalQueue(array $metrics): AnalysisQueue
    {
        $scores = [];
        
        foreach ($this->queues as $name => $queue) {
            $scores[$name] = $this->scoreQueue($queue, $metrics);
        }

        arsort($scores);
        $bestQueue = key($scores);
        
        return $this->queues[$bestQueue];
    }
}

class QueueWorkerPool
{
    private array $workers = [];
    private QueueManager $queueManager;
    private LoggerInterface $logger;
    private int $maxWorkers;

    public function __construct(
        QueueManager $queueManager,
        LoggerInterface $logger,
        int $maxWorkers = 5
    ) {
        $this->queueManager = $queueManager;
        $this->logger = $logger;
        $this->maxWorkers = $maxWorkers;
    }

    public function start(): void
    {
        while (count($this->workers) < $this->maxWorkers) {
            $this->spawnWorker();
        }

        while (true) {
            $this->manageWorkers();
            sleep(1);
        }
    }

    private function spawnWorker(): void
    {
        $worker = new AnalysisWorker(
            $this->queueManager,
            new AnalysisEngine(),
            $this->logger
        );

        $pid = pcntl_fork();
        
        if ($pid == -1) {
            throw new \RuntimeException('Could not fork worker process');
        }

        if ($pid) {
            // Parent process
            $this->workers[$pid] = [
                'pid' => $pid,
                'started' => time()
            ];
        } else {
            // Child process
            $worker->start();
            exit();
        }
    }

    private function manageWorkers(): void
    {
        foreach ($this->workers as $pid => $info) {
            if (!posix_kill($pid, 0)) {
                // Worker died, remove it
                unset($this->workers[$pid]);
                $this->logger->warning('Worker died', ['pid' => $pid]);
                continue;
            }

            if (time() - $info['started'] > 3600) {
                // Worker has been running for too long, restart it
                posix_kill($pid, SIGTERM);
                unset($this->workers[$pid]);
                $this->logger->info('Restarting worker', ['pid' => $pid]);
            }
        }

        // Spawn new workers if needed
        while (count($this->workers) < $this->maxWorkers) {
            $this->spawnWorker();
        }
    }
}
