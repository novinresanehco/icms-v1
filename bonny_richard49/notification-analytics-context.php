<?php

namespace App\Core\Notification\Analytics\Context;

class AnalyticsContext
{
    private array $attributes = [];
    private array $stack = [];
    private array $tags = [];
    private array $metrics = [];

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function pushContext(array $context): void
    {
        $this->stack[] = $context;
        $this->recordMetric('push', count($context));
    }

    public function popContext(): ?array
    {
        if (empty($this->stack)) {
            return null;
        }
        
        $context = array_pop($this->stack);
        $this->recordMetric('pop', count($context));
        return $context;
    }

    public function addTag(string $tag, $value = true): void
    {
        $this->tags[$tag] = $value;
    }

    public function hasTag(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    public function getTagValue(string $tag)
    {
        return $this->tags[$tag] ?? null;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function merge(AnalyticsContext $other): void
    {
        $this->attributes = array_merge($this->attributes, $other->attributes);
        $this->tags = array_merge($this->tags, $other->tags);
        $this->recordMetric('merge', count($other->attributes));
    }

    public function fork(): self
    {
        $fork = new self();
        $fork->attributes = $this->attributes;
        $fork->tags = $this->tags;
        $this->recordMetric('fork', 1);
        return $fork;
    }

    public function clear(): void
    {
        $this->attributes = [];
        $this->stack = [];
        $this->tags = [];
        $this->recordMetric('clear', 1);
    }

    private function recordMetric(string $operation, int $value): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'count' => 0,
                'total' => 0
            ];
        }

        $this->metrics[$operation]['count']++;
        $this->metrics[$operation]['total'] += $value;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class ContextManager
{
    private array $contexts = [];
    private array $globalTags = [];
    private array $metrics = [];

    public function createContext(string $id): AnalyticsContext
    {
        $context = new AnalyticsContext();
        foreach ($this->globalTags as $tag => $value) {
            $context->addTag($tag, $value);
        }
        
        $this->contexts[$id] = $context;
        $this->recordMetric('create');
        
        return $context;
    }

    public function getContext(string $id): ?AnalyticsContext
    {
        return $this->contexts[$id] ?? null;
    }

    public function removeContext(string $id): void
    {
        if (isset($this->contexts[$id])) {
            unset($this->contexts[$id]);
            $this->recordMetric('remove');
        }
    }

    public function addGlobalTag(string $tag, $value = true): void
    {
        $this->globalTags[$tag] = $value;
        foreach ($this->contexts as $context) {
            $context->addTag($tag, $value);
        }
        $this->recordMetric('global_tag');
    }

    public function getGlobalTags(): array
    {
        return $this->globalTags;
    }

    private function recordMetric(string $operation): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = 0;
        }
        $this->metrics[$operation]++;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class ContextBuilder
{
    private array $attributes = [];
    private array $tags = [];
    
    public function setAttribute(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function addTag(string $tag, $value = true): self
    {
        $this->tags[$tag] = $value;
        return $this;
    }

    public function build(): AnalyticsContext
    {
        $context = new AnalyticsContext();
        
        foreach ($this->attributes as $key => $value) {
            $context->setAttribute($key, $value);
        }
        
        foreach ($this->tags as $tag => $value) {
            $context->addTag($tag, $value);
        }
        
        return $context;
    }
}
