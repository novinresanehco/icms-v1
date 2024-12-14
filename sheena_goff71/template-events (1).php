<?php

namespace App\Core\Template\Events;

class TemplateEventDispatcher
{
    private SecurityValidator $security;
    private array $handlers = [];
    private array $monitors = [];

    public function __construct(SecurityValidator $security)
    {
        $this->security = $security;
    }

    public function dispatch(string $event, array $data): void
    {
        $validatedData = $this->security->validateEventData($data);
        
        foreach ($this->getHandlers($event) as $handler) {
            $handler->handle($validatedData);
        }

        foreach ($this->monitors as $monitor) {
            $monitor->recordEvent($event, $validatedData);
        }
    }

    public function subscribe(string $event, EventHandler $handler): void
    {
        $this->handlers[$event][] = $handler;
    }

    private function getHandlers(string $event): array
    {
        return $this->handlers[$event] ?? [];
    }
}

class TemplateMonitor
{
    private SecurityValidator $security;
    private array $metrics = [];

    public function recordEvent(string $event, array $data): void
    {
        $this->metrics[] = [
            'event' => $event,
            'timestamp' => microtime(true),
            'data' => $this->security->sanitizeMetrics($data)
        ];
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class RenderEventHandler implements EventHandler
{
    private TemplateCache $cache;
    private PerformanceMonitor $monitor;

    public function handle(array $data): void
    {
        $startTime = microtime(true);
        
        try {
            $this->processRender($data);
        } finally {
            $this->monitor->recordMetric(
                'render_time',
                microtime(true) - $startTime
            );
        }
    }

    private function processRender(array $data): void
    {
        $this->cache->remember(
            $data['template'],
            $data['content'],
            fn() => $data['result']
        );
    }
}

class SecurityEventHandler implements EventHandler
{
    private SecurityValidator $security;
    private LogManager $logger;

    public function handle(array $data): void
    {
        if ($this->security->detectThreat($data)) {
            $this->logger->critical('Security threat detected', [
                'event' => $data['event'],
                'context' => $data['context']
            ]);
            
            throw new SecurityException('Invalid template operation');
        }
    }
}

interface EventHandler
{
    public function handle(array $data): void;
}
