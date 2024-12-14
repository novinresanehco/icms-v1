namespace App\Core\Infrastructure;

class InfrastructureManager
{
    private CacheManager $cache;
    private EventDispatcher $events;
    private ErrorHandler $errors;
    private MetricsCollector $metrics;

    public function __construct(
        CacheManager $cache,
        EventDispatcher $events,
        ErrorHandler $errors,
        MetricsCollector $metrics
    ) {
        $this->cache = $cache;
        $this->events = $events;
        $this->errors = $errors;
        $this->metrics = $metrics;
    }

    public function executeOperation(callable $operation, array $context = []): mixed
    {
        $this->metrics->startOperation();
        try {
            DB::beginTransaction();
            $result = $operation();
            DB::commit();
            $this->events->dispatch(new OperationSucceeded($context));
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        } finally {
            $this->metrics->endOperation();
        }
    }

    private function handleFailure(Exception $e, array $context): void
    {
        $this->errors->handle($e);
        $this->events->dispatch(new OperationFailed($e, $context));
    }
}

class CacheManager
{
    private Cache $store;
    private array $tags = [];
    
    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        return $this->store
            ->tags($this->tags)
            ->remember($key, $ttl ?? $this->getDefaultTtl(), $callback);
    }

    public function invalidate(array $tags = []): void
    {
        $this->store->tags($tags ?: $this->tags)->flush();
    }

    private function getDefaultTtl(): int
    {
        return config('cache.ttl', 3600);
    }
}

class EventDispatcher
{
    private Dispatcher $dispatcher;
    private array $listeners = [];

    public function dispatch(Event $event): void
    {
        $this->dispatcher->dispatch($event);
    }

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }
}

class ErrorHandler
{
    private Logger $logger;
    private array $handlers = [];

    public function handle(Exception $e): void
    {
        $this->logger->error($e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        foreach ($this->getHandlers($e) as $handler) {
            $handler->handle($e);
        }
    }

    private function getHandlers(Exception $e): array
    {
        return array_filter($this->handlers, fn($handler) => 
            $handler->supports($e)
        );
    }
}

class MetricsCollector
{
    private Stats $stats;
    private array $current = [];

    public function startOperation(): void
    {
        $this->current = [
            'start' => microtime(true),
            'memory' => memory_get_usage()
        ];
    }

    public function endOperation(): void
    {
        $duration = microtime(true) - $this->current['start'];
        $memory = memory_get_usage() - $this->current['memory'];
        
        $this->stats->record([
            'duration' => $duration,
            'memory' => $memory,
            'time' => time()
        ]);
    }
}

interface Event
{
    public function getContext(): array;
}

class OperationSucceeded implements Event
{
    private array $context;
    
    public function __construct(array $context)
    {
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class OperationFailed implements Event
{
    private Exception $exception;
    private array $context;

    public function __construct(Exception $exception, array $context)
    {
        $this->exception = $exception;
        $this->context = $context;
    }

    public function getException(): Exception
    {
        return $this->exception;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class Cache
{
    private Store $store;
    private array $tags = [];

    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $key = $this->generateKey($key);
        if ($value = $this->store->get($key)) {
            return $value;
        }
        $value = $callback();
        $this->store->put($key, $value, $ttl);
        return $value;
    }

    public function flush(): void
    {
        $this->store->flush($this->tags);
    }

    private function generateKey(string $key): string
    {
        return md5(serialize([$key, $this->tags]));
    }
}

interface Logger
{
    public function error(string $message, array $context = []): void;
}

interface Stats
{
    public function record(array $metrics): void;
}

interface ErrorHandlerInterface
{
    public function handle(Exception $e): void;
    public function supports(Exception $e): bool;
}
