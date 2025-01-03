<?php

namespace App\Core\Events;

class EventDispatcher implements EventDispatcherInterface
{
    protected array $listeners = [];
    protected AuditLogger $logger;
    protected MetricsCollector $metrics;

    public function dispatch(string $event, array $payload = []): void
    {
        $startTime = microtime(true);

        try {
            foreach ($this->getListeners($event) as $listener) {
                $listener->handle($payload);
            }
            
            $this->recordSuccess($event, $startTime);
        } catch (\Exception $e) {
            $this->handleFailure($event, $e);
            throw $e;
        }
    }

    public function subscribe(string $event, EventListener $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    protected function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    protected function recordSuccess(string $event, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record('event.duration', $duration, [
            'event' => $event,
            'status' => 'success'
        ]);
    }

    protected function handleFailure(string $event, \Exception $e): void
    {
        $this->logger->error('Event dispatch failed', [
            'event' => $event,
            'error' => $e->getMessage()
        ]);

        $this->metrics->increment('event.failure', [
            'event' => $event,
            'error' => get_class($e)
        ]);
    }
}

class EventListener
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $logger;

    public function handle(array $payload): void
    {
        try {
            $this->validate($payload);
            $this->process($payload);
            $this->log($payload);
        } catch (\Exception $e) {
            $this->handleError($e, $payload);
            throw $e;
        }
    }

    protected function validate(array $payload): void
    {
        if (!$this->validator->validate($payload)) {
            throw new ValidationException('Invalid event payload');
        }
    }

    protected function log(array $payload): void
    {
        $this->logger->info('Event processed', [
            'listener' => static::class,
            'payload' => $payload
        ]);
    }

    abstract protected function process(array $payload): void;
    
    protected function handleError(\Exception $e, array $payload): void
    {
        $this->logger->error('Event processing failed', [
            'listener' => static::class,
            'payload' => $payload,
            'error' => $e->getMessage()
        ]);
    }
}

class ContentCreatedEvent implements EventInterface
{
    private Content $content;
    private User $user;

    public function __construct(Content $content, User $user)
    {
        $this->content = $content;
        $this->user = $user;
    }

    public function getData(): array
    {
        return [
            'content_id' => $this->content->id,
            'user_id' => $this->user->id,
            'type' => $this->content->type,
            'timestamp' => time()
        ];
    }
}

class ContentIndexingListener extends EventListener
{
    private SearchIndexer $indexer;

    protected function process(array $payload): void
    {
        $content = Content::findOrFail($payload['content_id']);
        $this->indexer->index($content);
    }
}

class ContentCacheInvalidationListener extends EventListener
{
    private CacheManager $cache;

    protected function process(array $payload): void
    {
        $this->cache->tags(['content'])->flush();
    }
}

interface EventInterface
{
    public function getData(): array;
}

interface EventDispatcherInterface
{
    public function dispatch(string $event, array $payload = []): void;
    public function subscribe(string $event, EventListener $listener): void;
}
