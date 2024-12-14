<?php

namespace App\Core\Monitoring\Events;

class EventMonitor
{
    private EventCollector $collector;
    private EventProcessor $processor;
    private EventStorage $storage;
    private EventAnalyzer $analyzer;
    private AlertManager $alertManager;

    public function monitorEvent(Event $event): void
    {
        $context = $this->collector->collectContext($event);
        $enrichedEvent = $this->processor->process($event, $context);
        
        $analysis = $this->analyzer->analyze($enrichedEvent);
        
        if ($analysis->requiresAction()) {
            $this->handleActions($analysis);
        }
        
        $this->storage->store($enrichedEvent);
    }

    private function handleActions(EventAnalysis $analysis): void
    {
        foreach ($analysis->getActions() as $action) {
            try {
                $action->execute();
                $this->storage->storeAction($action);
            } catch (\Exception $e) {
                $this->alertManager->notifyActionFailure($action, $e);
            }
        }
    }
}

class EventCollector
{
    private ContextBuilder $contextBuilder;
    private array $collectors;

    public function collectContext(Event $event): EventContext
    {
        $context = $this->contextBuilder->buildBaseContext();

        foreach ($this->collectors as $collector) {
            if ($collector->supports($event)) {
                $context->addData(
                    $collector->getType(),
                    $collector->collect($event)
                );
            }
        }

        return $context;
    }
}

class EventProcessor
{
    private array $processors;
    private EventValidator $validator;
    private EventEnricher $enricher;

    public function process(Event $event, EventContext $context): EnrichedEvent
    {
        $this->validator->validate($event);
        
        $processedEvent = $event;
        foreach ($this->processors as $processor) {
            if ($processor->supports($processedEvent)) {
                $processedEvent = $processor->process($processedEvent, $context);
            }
        }

        return $this->enricher->enrich($processedEvent, $context);
    }
}

class EventAnalyzer
{
    private array $analyzers;
    private RuleEngine $ruleEngine;
    private PatternMatcher $patternMatcher;

    public function analyze(EnrichedEvent $event): EventAnalysis
    {
        $results = [];
        
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->supports($event)) {
                $results[] = $analyzer->analyze($event);
            }
        }

        $patterns = $this->patternMatcher->findPatterns($event);
        $actions = $this->ruleEngine->evaluateRules($event, $patterns);

        return new EventAnalysis($results, $patterns, $actions);
    }
}

class EventStorage
{
    private \PDO $db;
    private string $eventsTable;
    private string $actionsTable;
    private EventSerializer $serializer;

    public function store(EnrichedEvent $event): void
    {
        $data = $this->serializer->serialize($event);
        
        $sql = "INSERT INTO {$this->eventsTable} 
                (type, data, context, created_at) 
                VALUES (?, ?, ?, NOW())";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $event->getType(),
            json_encode($data),
            json_encode($event->getContext())
        ]);
    }

    public function storeAction(EventAction $action): void
    {
        $sql = "INSERT INTO {$this->actionsTable} 
                (event_id, type, data, status, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $action->getEventId(),
            $action->getType(),
            json_encode($action->getData()),
            $action->getStatus()
        ]);
    }
}

class Event
{
    private string $id;
    private string $type;
    private array $data;
    private float $timestamp;
    private ?string $source;

    public function __construct(string $type, array $data, ?string $source = null)
    {
        $this->id = uniqid('evt_', true);
        $this->type = $type;
        $this->data = $data;
        $this->timestamp = microtime(true);
        $this->source = $source;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}

class EnrichedEvent extends Event
{
    private EventContext $context;
    private array $metadata;

    public function __construct(Event $event, EventContext $context, array $metadata = [])
    {
        parent::__construct($event->getType(), $event->getData(), $event->getSource());
        $this->context = $context;
        $this->metadata = $metadata;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }
}

class EventContext
{
    private array $data = [];
    private array $tags = [];
    private float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    public function addData(string $type, array $data): void
    {
        $this->data[$type] = $data;
    }

    public function getData(string $type): ?array
    {
        return $this->data[$type] ?? null;
    }

    public function addTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class EventAnalysis
{
    private array $results;
    private array $patterns;
    private array $actions;
    private float $timestamp;

    public function __construct(array $results, array $patterns, array $actions)
    {
        $this->results = $results;
        $this->patterns = $patterns;
        $this->actions = $actions;
        $this->timestamp = microtime(true);
    }

    public function requiresAction(): bool
    {
        return !empty($this->actions);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getPatterns(): array
    {
        return $this->patterns;
    }

    public function getActions(): array
    {
        return $this->actions;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

interface EventAction
{
    public function execute(): void;
    public function getType(): string;
    public function getEventId(): string;
    public function getData(): array;
    public function getStatus(): string;
}
