namespace App\Core\Events;

class EventManager implements EventManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private LogManager $logger;
    private array $handlers = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricsCollector $metrics,
        LogManager $logger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function dispatch(Event $event): void
    {
        $startTime = microtime(true);

        try {
            $this->security->executeCriticalOperation(
                new EventDispatchOperation(
                    $event,
                    $this->getHandlers($event),
                    $this->logger
                ),
                SecurityContext::fromRequest()
            );

            $this->metrics->timing(
                "event.{$event->getName()}.duration",
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            $this->handleEventFailure($event, $e);
            throw $e;
        }
    }

    public function subscribe(string $event, callable $handler, int $priority = 0): void
    {
        $this->validateHandler($handler);

        $this->handlers[$event][$priority][] = $handler;
        $this->clearHandlerCache($event);
    }

    public function unsubscribe(string $event, callable $handler): void
    {
        foreach ($this->handlers[$event] ?? [] as $priority => $handlers) {
            foreach ($handlers as $key => $existingHandler) {
                if ($existingHandler === $handler) {
                    unset($this->handlers[$event][$priority][$key]);
                }
            }
        }

        $this->clearHandlerCache($event);
    }

    private function getHandlers(Event $event): array
    {
        $cacheKey = $this->getHandlerCacheKey($event);

        return $this->cache->remember($cacheKey, 3600, function () use ($event) {
            return $this->compileHandlers($event->getName());
        });
    }

    private function compileHandlers(string $eventName): array
    {
        $handlers = $this->handlers[$eventName] ?? [];
        
        if (empty($handlers)) {
            return [];
        }

        krsort($handlers);
        return array_merge(...$handlers);
    }

    private function validateHandler(callable $handler): void
    {
        if (!is_callable($handler)) {
            throw new InvalidHandlerException('Event handler must be callable');
        }

        if ($handler instanceof \Closure) {
            $reflection = new \ReflectionFunction($handler);
        } else {
            $reflection = new \ReflectionMethod($handler[0], $handler[1]);
        }

        if (!$reflection->hasReturnType() || 
            $reflection->getReturnType()->getName() !== 'void') {
            throw new InvalidHandlerException('Event handler must return void');
        }
    }

    private function handleEventFailure(Event $event, \Exception $e): void
    {
        $this->metrics->increment("event.{$event->getName()}.failures");

        $this->logger->critical('Event handling failed', [
            'event' => $event->getName(),
            'data' => $event->getData(),
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($event->isCritical()) {
            $this->handleCriticalEventFailure($event, $e);
        }
    }

    private function handleCriticalEventFailure(Event $event, \Exception $e): void
    {
        $this->security->executeCriticalOperation(
            new CriticalEventFailureOperation(
                $event,
                $e,
                $this->logger
            ),
            SecurityContext::fromRequest()
        );

        $this->metrics->increment('events.critical_failures');
    }

    private function getHandlerCacheKey(Event $event): string
    {
        return sprintf('event_handlers:%s', $event->getName());
    }

    private function clearHandlerCache(string $event): void
    {
        $this->cache->forget($this->getHandlerCacheKey(new $event));
    }

    public function getRegisteredEvents(): array
    {
        return array_keys($this->handlers);
    }

    public function hasHandlers(string $event): bool
    {
        return !empty($this->handlers[$event]);
    }

    public function clearHandlers(): void
    {
        $this->handlers = [];
        $this->cache->tags(['event_handlers'])->flush();
    }
}
