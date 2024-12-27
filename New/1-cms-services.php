<?php

namespace App\Services;

class CacheService implements CacheInterface
{
    private CacheStore $store;
    private SecurityService $security;
    private int $ttl;

    public function remember(string $key, callable $callback): mixed
    {
        if ($cached = $this->get($key)) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value);
        return $value;
    }

    private function get(string $key): mixed
    {
        $value = $this->store->get($key);
        return $value ? $this->security->decrypt($value) : null;
    }

    private function set(string $key, mixed $value): void
    {
        $encrypted = $this->security->encrypt($value);
        $this->store->put($key, $encrypted, $this->ttl);
    }
}

class LoggerService implements LoggerInterface
{
    private LoggerClient $client;

    public function security(string $event, array $data = []): void
    {
        $this->log('security', $event, $data);
    }

    public function audit(string $event, array $data = []): void
    {
        $this->log('audit', $event, $data);
    }

    private function log(string $channel, string $event, array $data): void
    {
        $entry = [
            'timestamp' => time(),
            'channel' => $channel,
            'event' => $event,
            'data' => $data
        ];

        $this->client->log($entry);
    }
}

class ValidationService implements ValidationInterface
{
    private array $rules = [];

    public function validate(array $data, array $rules = []): array
    {
        $rules = $rules ?: $this->rules;
        
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field], $rule)) {
                throw new ValidationException("Validation failed for {$field}");
            }
        }

        return $data;
    }

    private function validateField(mixed $value, string $rule): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'array' => is_array($value),
            default => true
        };
    }
}
