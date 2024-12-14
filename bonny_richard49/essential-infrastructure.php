<?php

namespace App\Core\Infrastructure;

class CacheManager
{
    private $store;
    private $prefix;

    public function get(string $key)
    {
        return $this->store->get($this->prefix . $key);
    }

    public function put(string $key, $value, int $ttl = 3600): void
    {
        $this->store->put($this->prefix . $key, $value, $ttl);
    }

    public function remember(string $key, int $ttl, callable $callback)
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        return $value;
    }
}

class ErrorHandler
{
    private $logger;

    public function handle(\Throwable $e): void
    {
        $this->logger->error($e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof HttpException) {
            $this->handleHttpException($e);
        } else {
            $this->handleCriticalError($e);
        }
    }

    private function handleHttpException(HttpException $e): void
    {
        http_response_code($e->getCode());
        echo json_encode(['error' => $e->getMessage()]);
    }

    private function handleCriticalError(\Throwable $e): void
    {
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
}

class DatabaseManager
{
    private $connection;
    private $transactions = 0;

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->connection->beginTransaction();
        }
        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 1) {
            $this->connection->commit();
        }
        $this->transactions = max(0, $this->transactions - 1);
    }

    public function rollBack(): void
    {
        if ($this->transactions === 1) {
            $this->connection->rollBack();
        }
        $this->transactions = max(0, $this->transactions - 1);
    }
}

class LogManager
{
    private $handlers = [];

    public function log(string $level, string $message, array $context = []): void
    {
        foreach ($this->handlers as $handler) {
            $handler->handle($level, $message, $context);
        }
    }

    public function addHandler(LogHandler $handler): void
    {
        $this->handlers[] = $handler;
    }
}

interface LogHandler
{
    public function handle(string $level, string $message, array $context): void;
}
