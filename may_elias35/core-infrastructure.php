<?php

namespace App\Core\Infrastructure;

class CacheManager
{
    protected $store;
    protected $prefix;
    protected $defaultTtl = 3600;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->store->get($this->prefix . $key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->store->put($this->prefix . $key, $value, $ttl ?? $this->defaultTtl);
        
        return $value;
    }

    public function invalidate(string $key): void
    {
        $this->store->forget($this->prefix . $key);
    }

    public function flush(): void
    {
        $this->store->flush();
    }
}

class ErrorHandler
{
    protected LogManager $logger;
    protected array $levels = [
        'critical' => 1,
        'error' => 2,
        'warning' => 3,
        'info' => 4
    ];

    public function handle(\Exception $e): void
    {
        $level = $this->determineLevel($e);
        
        $this->logger->log($level, $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() ? get_class($e->getPrevious()) : null
        ]);

        if ($this->shouldNotify($level)) {
            $this->notify($e);
        }

        if ($this->shouldRethrow($e)) {
            throw $e;
        }
    }

    protected function determineLevel(\Exception $e): string
    {
        return match(true) {
            $e instanceof SecurityException => 'critical',
            $e instanceof ValidationException => 'warning',
            default => 'error'
        };
    }

    protected function shouldNotify(string $level): bool
    {
        return $this->levels[$level] <= $this->levels['error'];
    }

    protected function shouldRethrow(\Exception $e): bool
    {
        return $e instanceof SecurityException || 
               $e instanceof CriticalException;
    }
}

class LogManager
{
    protected $handlers = [];
    protected $processors = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $record = [
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'time' => time(),
            'extra' => []
        ];

        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }

        foreach ($this->handlers as $handler) {
            if ($handler->handles($record['level'])) {
                $handler->handle($record);
            }
        }
    }
}

class FileHandler
{
    protected string $path;
    protected array $levels;

    public function handle(array $record): void
    {
        $formatted = $this->format($record);
        file_put_contents(
            $this->path, 
            $formatted . PHP_EOL, 
            FILE_APPEND | LOCK_EX
        );
    }

    public function handles(string $level): bool
    {
        return in_array($level, $this->levels);
    }

    protected function format(array $record): string
    {
        return sprintf(
            '[%s] %s: %s %s',
            date('Y-m-d H:i:s', $record['time']),
            strtoupper($record['level']),
            $record['message'],
            json_encode($record['context'])
        );
    }
}

class PerformanceMonitor
{
    protected $metrics = [];
    protected $threshold = [
        'response_time' => 200,
        'memory_usage' => 128 * 1024 * 1024,
        'query_time' => 50
    ];

    public function start(string $operation): void
    {
        $this->metrics[$operation] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true)
        ];
    }

    public function end(string $operation): array
    {
        if (!isset($this->metrics[$operation])) {
            throw new \RuntimeException("Operation not started: $operation");
        }

        $metrics = [
            'time' => microtime(true) - $this->metrics[$operation]['start_time'],
            'memory' => memory_get_usage(true) - $this->metrics[$operation]['start_memory']
        ];

        if ($metrics['time'] * 1000 > $this->threshold['response_time']) {
            $this->alert('response_time', $operation, $metrics['time']);
        }

        if ($metrics['memory'] > $this->threshold['memory_usage']) {
            $this->alert('memory_usage', $operation, $metrics['memory']);
        }

        return $metrics;
    }

    protected function alert(string $metric, string $operation, $value): void
    {
        app(LogManager::class)->log('warning', "Performance threshold exceeded", [
            'metric' => $metric,
            'operation' => $operation,
            'value' => $value,
            'threshold' => $this->threshold[$metric]
        ]);
    }
}
