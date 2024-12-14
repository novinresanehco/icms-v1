// app/Core/Cache/Traits/HasCache.php
<?php

namespace App\Core\Cache\Traits;

use App\Core\Cache\CacheKeyGenerator;

trait HasCache
{
    public function getCacheKey(): string
    {
        return CacheKeyGenerator::generate(
            $this->getCachePrefix(),
            $this->getCacheKeyParams()
        );
    }

    public function getCacheTags(): array
    {
        return [$this->getCachePrefix()];
    }

    public function getCacheTtl(): ?int
    {
        return config('cache.ttl', 3600);
    }

    protected function getCachePrefix(): string
    {
        return strtolower(class_basename($this));
    }

    protected function getCacheKeyParams(): array
    {
        return [
            'id' => $this->id ?? null,
            'updated_at' => $this->updated_at ?? null
        ];
    }
}

// app/Core/Cache/InvalidationStrategy.php
<?php

namespace App\Core\Cache;

class InvalidationStrategy
{
    private array $triggers = [];
    private array $tags = [];
    
    public function addTrigger(string $event, array $tags): self
    {
        $this->triggers[$event] = array_merge(
            $this->triggers[$event] ?? [],
            $tags
        );
        return $this;
    }

    public function addTags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function shouldInvalidate(string $event): bool
    {
        return isset($this->triggers[$event]);
    }

    public function getTagsToInvalidate(string $event): array
    {
        return array_merge(
            $this->tags,
            $this->triggers[$event] ?? []
        );
    }
}

// app/Core/Cache/CacheInvalidator.php
<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheInvalidator
{
    private array $strategies = [];
    
    public function addStrategy(string $key, InvalidationStrategy $strategy): void
    {
        $this->strategies[$key] = $strategy;
    }

    public function invalidate(string $event): void
    {
        foreach ($this->strategies as $key => $strategy) {
            if ($strategy->shouldInvalidate($event)) {
                $this->invalidateTags($strategy->getTagsToInvalidate($event), $key);
            }
        }
    }

    private function invalidateTags(array $tags, string $key): void
    {
        try {
            Cache::tags($tags)->flush();
            Log::info("Cache invalidated for key {$key} with tags: " . implode(', ', $tags));
        } catch (\Exception $e) {
            Log::error("Cache invalidation error for key {$key}: " . $e->getMessage());
        }
    }
}

// app/Core/Cache/CachePolicy.php
<?php

namespace App\Core\Cache;

class CachePolicy
{
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'ttl' => 3600,
            'tags' => [],
            'triggers' => []
        ], $config);
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    public function getTtl(): int
    {
        return $this->config['ttl'];
    }

    public function getTags(): array
    {
        return $this->config['tags'];
    }

    public function getTriggers(): array
    {
        return $this->config['triggers'];
    }

    public function shouldCache(array $context = []): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        foreach ($this->config['conditions'] ?? [] as $condition) {
            if (!$this->evaluateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateCondition(array $condition, array $context): bool
    {
        $type = $condition['type'] ?? 'default';
        
        return match($type) {
            'time' => $this->evaluateTimeCondition($condition, $context),
            'request' => $this->evaluateRequestCondition($condition, $context),
            'custom' => $this->evaluateCustomCondition($condition, $context),
            default => true
        };
    }

    private function evaluateTimeCondition(array $condition, array $context): bool
    {
        return true;
    }

    private function evaluateRequestCondition(array $condition, array $context): bool
    {
        return true;
    }

    private function evaluateCustomCondition(array $condition, array $context): bool
    {
        return true;
    }
}