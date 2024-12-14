<?php

namespace App\Core\Notification\Analytics\Builder;

class AnalyticsBuilder
{
    private array $components = [];
    private array $filters = [];
    private array $transformers = [];
    private array $metrics = [];

    public function addComponent(string $name, ComponentInterface $component): self
    {
        $this->components[$name] = $component;
        return $this;
    }

    public function addFilter(string $name, callable $filter): self
    {
        $this->filters[$name] = $filter;
        return $this;
    }

    public function addTransformer(string $name, callable $transformer): self
    {
        $this->transformers[$name] = $transformer;
        return $this;
    }

    public function build(): Analytics
    {
        return new Analytics(
            $this->components,
            $this->filters,
            $this->transformers,
            $this->metrics
        );
    }

    private function recordMetrics(string $component, float $duration): void
    {
        if (!isset($this->metrics[$component])) {
            $this->metrics[$component] = [
                'builds' => 0,
                'total_duration' => 0
            ];
        }

        $this->metrics[$component]['builds']++;
        $this->metrics[$component]['total_duration'] += $duration;
    }
}

class Analytics
{
    private array $components;
    private array $filters;
    private array $transformers;
    private array $metrics;

    public function __construct(
        array $components,
        array $filters,
        array $transformers,
        array $metrics
    ) {
        $this->components = $components;
        $this->filters = $filters;
        $this->transformers = $transformers;
        $this->metrics = $metrics;
    }

    public function process(array $data): array
    {
        $processed = $data;

        foreach ($this->filters as $filter) {
            $processed = array_filter($processed, $filter);
        }

        foreach ($this->transformers as $transformer) {
            $processed = array_map($transformer, $processed);
        }

        return $processed;
    }

    public function getComponent(string $name): ?ComponentInterface
    {
        return $this->components[$name] ?? null;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

interface ComponentInterface
{
    public function execute(array $data): array;
}

class DataComponent implements ComponentInterface
{
    private array $config;
    private array $handlers = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function execute(array $data): array
    {
        $result = [];
        foreach ($data as $item) {
            $type = $item['type'] ?? 'default';
            if (isset($this->handlers[$type])) {
                $result[] = ($this->handlers[$type])($item, $this->config);
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }
}

class PipelineComponent implements ComponentInterface
{
    private array $steps = [];
    private array $errorHandlers = [];

    public function addStep(callable $step, ?callable $errorHandler = null): void
    {
        $this->steps[] = $step;
        if ($errorHandler) {
            $this->errorHandlers[] = $errorHandler;
        }
    }

    public function execute(array $data): array
    {
        $result = $data;
        foreach ($this->steps as $index => $step) {
            try {
                $result = array_map($step, $result);
            } catch (\Exception $e) {
                if (isset($this->errorHandlers[$index])) {
                    $result = ($this->errorHandlers[$index])($e, $result);
                } else {
                    throw $e;
                }
            }
        }
        return $result;
    }
}

class AggregationComponent implements ComponentInterface
{
    private array $aggregators = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addAggregator(string $name, callable $aggregator): void
    {
        $this->aggregators[$name] = $aggregator;
    }

    public function execute(array $data): array
    {
        $result = [];
        foreach ($this->aggregators as $name => $aggregator) {
            $result[$name] = $aggregator($data, $this->config);
        }
        return $result;
    }
}

class ValidationComponent implements ComponentInterface
{
    private array $validators = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addValidator(string $field, callable $validator): void
    {
        $this->validators[$field] = $validator;
    }

    public function execute(array $data): array
    {
        return array_filter($data, function($item) {
            foreach ($this->validators as $field => $validator) {
                if (isset($item[$field]) && !$validator($item[$field])) {
                    return false;
                }
            }
            return true;
        });
    }
}

class TransformationComponent implements ComponentInterface
{
    private array $transformers = [];

    public function addTransformer(string $field, callable $transformer): void
    {
        $this->transformers[$field] = $transformer;
    }

    public function execute(array $data): array
    {
        return array_map(function($item) {
            foreach ($this->transformers as $field => $transformer) {
                if (isset($item[$field])) {
                    $item[$field] = $transformer($item[$field]);
                }
            }
            return $item;
        }, $data);
    }
}
