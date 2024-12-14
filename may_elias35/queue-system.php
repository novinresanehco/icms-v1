<?php

namespace App\Core\Queue\Models;

use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    protected $fillable = [
        'name',
        'driver',
        'config',
        'status',
        'metadata'
    ];

    protected $casts = [
        'config' => 'array',
        'metadata' => 'array'
    ];
}

class Job extends Model
{
    protected $fillable = [
        'queue',
        'payload',
        'attempts',
        'reserved_at',
        'available_at',
        'created_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'reserved_at' => 'datetime',
        'available_at' => 'datetime'
    ];
}

class FailedJob extends Model
{
    protected $fillable = [
        'connection',
        'queue',
        'payload',
        'exception',
        'failed_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'failed_at' => 'datetime'
    ];
}

namespace App\Core\Queue\Services;

class QueueManager
{
    protected array $queues = [];
    protected array $drivers = [];
    protected ?string $defaultQueue = null;

    public function addQueue(string $name, array $config = []): void
    {
        $this->queues[$name] = $config;
    }

    public function addDriver(string $name, QueueDriver $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function setDefaultQueue(string $name): void
    {
        $this->defaultQueue = $name;
    }

    public function push(Job $job, string $queue = null): string
    {
        $queue = $queue ?? $this->defaultQueue;
        $driver = $this->getDriver($queue);
        return $driver->push($job, $queue);
    }

    public function pop(string $queue = null): ?Job
    {
        $queue = $queue ?? $this->defaultQueue;
        $driver = $this->getDriver($queue);
        return $driver->pop($queue);
    }

    protected function getDriver(string $queue): QueueDriver
    {
        $config = $this->queues[$queue] ?? null;
        if (!$config) {
            throw new QueueException("Queue {$queue} not found");
        }

        $driver = $this->drivers[$config['driver']] ?? null;
        if (!$driver) {
            throw new QueueException("Driver {$config['driver']} not found");
        }

        return $driver;
    }
}

abstract class QueueDriver
{
    abstract public function push(Job $job, string $queue): string;
    abstract public function pop(string $queue): ?Job;
    abstract public function delete(string $id): void;
    abstract public function release(Job $job, int $delay = 0): void;
}

class DatabaseDriver extends QueueDriver
{
    public function push(Job $job, string $queue): string
    {
        $id = (string) Job::create([
            'queue' => $queue,
            'payload' => $job->toArray(),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now(),
            'created_at' => now()
        ])->id;

        return $id;
    }

    public function pop(string $queue): ?Job
    {
        $job = Job::where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->first();

        if ($job) {
            $job->update(['reserved_at' => now()]);
            return $job;
        }

        return null;
    }

    public function delete(string $id): void
    {
        Job::where('id', $id)->delete();
    }

    public function release(Job $job, int $delay = 0): void
    {
        $job->update([
            'reserved_at' => null,
            'available_at' => now()->addSeconds($delay)
        ]);
    }
}

class RedisDriver extends QueueDriver
{
    private $redis;

    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    public function push(Job $job, string $queue): string
    {
        $id = uniqid('job:', true);
        $this->redis->rpush("queue:{$queue}", json_encode([
            'id' => $id,
            'job' => $job->toArray()
        ]));
        return $id;
    }

    public function pop(string $queue): ?Job
    {
        $payload = $this->redis->lpop("queue:{$queue}");
        if ($payload) {
            $data = json_decode($payload, true);
            return new Job($data['job']);
        }
        return null;
    }

    public function delete(string $id): void
    {
        $this->redis->del("job:{$id}");
    }

    public function release(Job $job, int $delay = 0): void
    {
        if ($delay > 0) {
            $this->redis->zadd(
                'delayed_queue',
                time() + $delay,
                json_encode($job->toArray())
            );
        } else {
            $this->push($job, $job->queue);
        }
    }
}

class Worker
{
    private QueueManager $manager;
    private JobProcessor $processor;
    private FailureHandler $failureHandler;

    public function __construct(
        QueueManager $manager,
        JobProcessor $processor,
        FailureHandler $failureHandler
    ) {
        $this->manager = $manager;
        $this->processor = $processor;
        $this->failureHandler = $failureHandler;
    }

    public function work(string $queue = null): void
    {
        while (true) {
            if ($job = $this->manager->pop($queue)) {
                try {
                    $this->processor->process($job);
                    $this->manager->delete($job->id);
                } catch (\Exception $e) {
                    $this->handleFailedJob($job, $e);
                }
            } else {
                sleep(1);
            }
        }
    }

    protected function handleFailedJob(Job $job, \Exception $e): void
    {
        $job->attempts++;

        if ($job->attempts < $job->maxAttempts) {
            $this->manager->release($job, 10 * $job->attempts);
        } else {
            $this->failureHandler->handle($job, $e);
        }
    }
}

namespace App\Core\Queue\Console;

use Illuminate\Console\Command;

class WorkCommand extends Command
{
    protected $signature = 'queue:work {queue?}';
    protected $description = 'Start processing jobs on the queue';

    public function handle(Worker $worker): void
    {
        $queue = $this->argument('queue');
        $this->info("Processing jobs from the {$queue} queue...");
        $worker->work($queue);
    }
}

class RetryCommand extends Command
{
    protected $signature = 'queue:retry {id*}';
    protected $description = 'Retry a failed queue job';

    public function handle(QueueManager $manager): void
    {
        foreach ($this->argument('id') as $id) {
            $failed = FailedJob::find($id);
            if ($failed) {
                $job = unserialize($failed->payload);
                $manager->push($job, $failed->queue);
                $failed->delete();
                $this->info("Job {$id} has been pushed back onto the queue.");
            }
        }
    }
}
