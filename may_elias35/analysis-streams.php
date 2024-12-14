<?php

namespace App\Core\Audit\Streams;

class DataStream
{
    private $source;
    private array $processors = [];
    private array $filters = [];
    private LoggerInterface $logger;

    public function __construct($source, LoggerInterface $logger)
    {
        $this->source = $source;
        $this->logger = $logger;
    }

    public function addProcessor(ProcessorInterface $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    public function addFilter(FilterInterface $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function process(): \Generator
    {
        try {
            foreach ($this->source as $item) {
                if (!$this->shouldProcess($item)) {
                    continue;
                }

                $processed = $this->processItem($item);
                if ($processed !== null) {
                    yield $processed;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Stream processing error', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function shouldProcess($item): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter->apply($item)) {
                return false;
            }
        }
        return true;
    }

    private function processItem($item)
    {
        $result = $item;
        foreach ($this->processors as $processor) {
            $result = $processor->process($result);
            if ($result === null) {
                return null;
            }
        }
        return $result;
    }
}

class AnalysisStream implements \IteratorAggregate
{
    private array $data;
    private array $transformers = [];
    private array $aggregators = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function transform(callable $transformer): self
    {
        $this->transformers[] = $transformer;
        return $this;
    }

    public function aggregate(callable $aggregator): self
    {
        $this->aggregators[] = $aggregator;
        return $this;
    }

    public function getIterator(): \Generator
    {
        foreach ($this->data as $item) {
            $transformed = $this->applyTransformers($item);
            if ($transformed !== null) {
                yield $transformed;
            }
        }

        if (!empty($this->aggregators)) {
            yield from $this->applyAggregators();
        }
    }

    private function applyTransformers($item)
    {
        $result = $item;
        foreach ($this->transformers as $transformer) {
            $result = $transformer($result);
            if ($result === null) {
                return null;
            }
        }
        return $result;
    }

    private function applyAggregators(): \Generator
    {
        $accumulated = [];
        foreach ($this->data as $item) {
            foreach ($this->aggregators as $aggregator) {
                $accumulated[] = $aggregator($item);
            }
        }
        yield from $accumulated;
    }
}

class MetricsStream
{
    private MetricsCollector $metrics;
    private array $buffer = [];
    private int $bufferSize;
    private array $processors = [];

    public function __construct(MetricsCollector $metrics, int $bufferSize = 100)
    {
        $this->metrics = $metrics;
        $this->bufferSize = $bufferSize;
    }

    public function addProcessor(callable $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    public function push(array $metric): void
    {
        $this->buffer[] = $metric;

        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $processedMetrics = $this->processMetrics($this->buffer);
        foreach ($processedMetrics as $metric) {
            $this->metrics->record(
                $metric['name'],
                $metric['value'],
                $metric['tags'] ?? []
            );
        }

        $this->buffer = [];
    }

    private function processMetrics(array $metrics): array
    {
        $result = $metrics;
        foreach ($this->processors as $processor) {
            $result = array_map($processor, $result);
        }
        return array_filter($result);
    }
}

class EventStream
{
    private EventDispatcher $dispatcher;
    private array $handlers = [];
    private array $buffer = [];
    private int $bufferSize;

    public function __construct(EventDispatcher $dispatcher, int $bufferSize = 50)
    {
        $this->dispatcher = $dispatcher;
        $this->bufferSize = $bufferSize;
    }

    public function addHandler(string $eventType, callable $handler): self
    {
        if (!isset($this->handlers[$eventType])) {
            $this->handlers[$eventType] = [];
        }
        $this->handlers[$eventType][] = $handler;
        return $this;
    }

    public function push(Event $event): void
    {
        $this->buffer[] = $event;

        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        foreach ($this->buffer as $event) {
            $this->processEvent($event);
        }
        $this->buffer = [];
    }

    private function processEvent(Event $event): void
    {
        $eventType = get_class($event);
        
        if (isset($this->handlers[$eventType])) {
            foreach ($this->handlers[$eventType] as $handler) {
                $handler($event);
            }
        }

        $this->dispatcher->dispatch($event);
    }
}
