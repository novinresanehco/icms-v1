<?php

namespace App\Core\RateLimit\Services;

use App\Core\RateLimit\Exceptions\RateLimitValidationException;

class RateLimitValidator
{
    public function validateAttempt(string $key, array $options = []): void
    {
        $this->validateKey($key);
        $this->validateLimit($options['limit'] ?? null);
        $this->validateTTL($options['ttl'] ?? null);
    }

    protected function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new RateLimitValidationException('Rate limit key cannot be empty');
        }

        if (strlen($key) > 255) {
            throw new RateLimitValidationException('Rate limit key too long');
        }
    }

    protected function validateLimit(?int $limit): void
    {
        if ($limit !== null && $limit < 1) {
            throw new RateLimitValidationException('Rate limit must be at least 1');
        }

        $maxLimit = config('rate-limit.max_limit', 1000);
        if ($limit !== null && $limit > $maxLimit) {
            throw new RateLimitValidationException("Rate limit cannot exceed {$maxLimit}");
        }
    }

    protected function validateTTL(?int $ttl): void
    {
        if ($ttl !== null && $ttl < 1) {
            throw new RateLimitValidationException('TTL must be at least 1 second');
        }

        $maxTTL = config('rate-limit.max_ttl', 86400);
        if ($ttl !== null && $ttl > $maxTTL) {
            throw new RateLimitValidationException("TTL cannot exceed {$maxTTL} seconds");
        }
    }
}
