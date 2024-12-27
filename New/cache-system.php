<?php

namespace App\Core\Cache;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private SecurityValidator $security;
    private SerializationManager $serializer;
    private string $prefix;

    public function __construct(
        CacheStore $store,
        SecurityValidator $security,
        SerializationManager $serializer,
        string $prefix = 'cms:'
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->serializer = $serializer;
        $this->prefix = $prefix;
    }

    public function remember(array $key, callable $callback, ?int $ttl = 3600): mixed
    {
        $cacheKey = $this->buildKey($key);
        
        if ($cached = $this->get($cacheKey)) {
            return $cached;
        }

        $value = $callback();
        $this->set($cacheKey, $value, $ttl);
        
        return $value;
    }

    public function get(string $key): mixed
    {
        $operation = new CacheReadOperation($key, $this->store);
        $result = $this->security->validateOperation($operation);
        
        if (!$result->hasValue()) {
            return null;
        }

        return $this->serializer->unserialize($result->getValue());
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $serialized = $this->serializer->serialize($value);
        
        $operation = new CacheWriteOperation(
            $key, 
            $serialized,
            $ttl,
            $this->store
        );
        
        $result = $this->security->validateOperation($operation);
        return $result->isSuccess();
    }

    public function delete(string $key): bool
    {
        $operation = new CacheDeleteOperation($key, $this->store);
        $result = $this->security->validateOperation($operation);
        return $result->isSuccess();
    }

    public function invalidate(array $key): void
    {
        $this->delete($this->buildKey($key));
    }

    public function flush(): bool
    {
        $operation = new CacheFlushOperation($this->store);
        $result = $this->security->validateOperation($operation);
        return $result->isSuccess();
    }

    private function buildKey(array $parts): string
    {
        return $this->prefix . implode(':', array_map('strval', $parts));
    }
}

class CacheReadOperation implements Operation
{
    private string $key;
    private CacheStore $store;

    public function __construct(string $key, CacheStore $store)
    {
        $this->key = $key;
        $this->store = $store;
    }

    public function getData(): array
    {
        return ['key' => $this->key];
    }

    public function execute(): CacheResult
    {
        $value = $this->store->get($this->key);
        return new CacheResult($value !== null, $value);
    }
}

class CacheWriteOperation implements Operation
{
    private string $key;
    private string $value;
    private ?int $ttl;
    private CacheStore $store;

    public function __construct(
        string $key,
        string $value,
        ?int $ttl,
        CacheStore $store
    ) {
        $this->key = $key;
        $this->value = $value;
        $this->ttl = $ttl;
        $this->store = $store;
    }

    public function getData(): array
    {
        return [
            'key' => $this->key,
            'ttl' => $this->ttl
        ];
    }

    public function execute(): CacheResult
    {
        $success = $this->store->set($this->key, $this->value, $this->ttl);
        return new CacheResult($success);
    }
}

class CacheDeleteOperation implements Operation
{
    private string $key;
    private CacheStore $store;

    public function __construct(string $key, CacheStore $store)
    {
        $this->key = $key;
        $this->store = $store;
    }

    public function getData(): array
    {
        return ['key' => $this->key];
    }

    public function execute(): CacheResult
    {
        $success = $this->store->delete($this->key);
        return new CacheResult($success);
    }
}

class CacheFlushOperation implements Operation
{
    private CacheStore $store;

    public function __construct(CacheStore $store)
    {
        $this->store = $store;
    }

    public function getData(): array
    {
        return [];
    }

    public function execute(): CacheResult
    {
        $success = $this->store->flush();
        return new CacheResult($success);
    }
}

class CacheResult extends OperationResult
{
    private bool $success;
    private ?string $value;

    public function __construct(bool $success, ?string $value = null)
    {
        $this->success = $success;
        $this->value = $value;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }
}

interface CacheInterface
{
    public function remember(array $key, callable $callback, ?int $ttl = 3600): mixed;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function invalidate(array $key): void;
    public function flush(): bool;
}

interface CacheStore
{
    public function get(string $key): ?string;
    public function set(string $key, string $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function flush(): bool;
}

interface SerializationManager
{
    public function serialize(mixed $value): string;
    public function unserialize(string $value): mixed;
}