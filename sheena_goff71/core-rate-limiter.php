<?php

namespace App\Core\Security;

class RateLimitingService implements RateLimitInterface 
{
    private Cache $cache;
    private MetricsCollector $metrics;
    private AuditLogger $auditLogger;
    private ConfigurationManager $config;

    public function __construct(
        Cache $cache,
        MetricsCollector $metrics,
        AuditLogger $auditLogger,
        ConfigurationManager $config
    ) {
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function checkLimit(string $key, RateLimit $limit): bool
    {
        $operationId = $this->metrics->startOperation('rate_limit_check');

        try {
            // Get current window
            $window = $this->getCurrentWindow($key, $limit->window);
            
            // Check if limit exceeded
            if ($window->attempts >= $limit->maxAttempts) {
                $this->handleLimitExceeded($key, $window, $limit);
                return false;
            }

            // Increment attempt counter
            $window->increment();
            
            // Update window in cache
            $this->saveWindow($key, $window, $limit->window);

            $this->metrics->recordSuccess($operationId);
            
            return true;

        } catch (\Exception $e) {
            $this->metrics->recordFailure($operationId, $e);
            throw new RateLimitException('Rate limit check failed', 0, $e);
        }
    }

    public function checkMultiLimit(array $limits): bool
    {
        foreach ($limits as $key => $limit) {
            if (!$this->checkLimit($key, $limit)) {
                return false;
            }
        }
        return true;
    }

    public function getRemainingAttempts(string $key, RateLimit $limit): int
    {
        $window = $this->getCurrentWindow($key, $limit->window);
        return max(0, $limit->maxAttempts - $window->attempts);
    }

    public function getResetTime(string $key, RateLimit $limit): int
    {
        $window = $this->getCurrentWindow($key, $limit->window);
        return $window->expiresAt - time();
    }

    private function getCurrentWindow(string $key, int $windowSize): RateLimitWindow
    {
        $cacheKey = $this->buildCacheKey($key);
        
        $window = $this->cache->get($cacheKey);
        
        if (!$window) {
            $window = new RateLimitWindow(
                attempts: 0,
                startedAt: time(),
                expiresAt: time() + $windowSize
            );
        } else {
            $window = unserialize($window);
            
            // Check if window expired
            if ($window->isExpired()) {
                $window = new RateLimitWindow(
                    attempts: 0,
                    startedAt: time(),
                    expiresAt: time() + $windowSize
                );
            }
        }

        return $window;
    }

    private function saveWindow(string $key, RateLimitWindow $window, int $windowSize): void
    {
        $cacheKey = $this->buildCacheKey($key);
        $this->cache->put($cacheKey, serialize($window), $windowSize);
    }

    private function handleLimitExceeded(string $key, RateLimitWindow $window, RateLimit $limit): void
    {
        // Log event
        $this->auditLogger->logSecurityEvent(
            new SecurityEvent(
                SecurityEventType::RATE_LIMIT_EXCEEDED,
                "Rate limit exceeded for key: {$key}",
                SecurityLevel::WARNING,
                [
                    'key' => $key,
                    'attempts' => $window->attempts,
                    'limit' => $limit->maxAttempts,
                    'window' => $limit->window
                ]
            )
        );

        // Update metrics
        $this->metrics->incrementRateLimitExceeded($key);

        // Check for abuse
        $this->checkForAbuse($key, $window, $limit);
    }

    private function checkForAbuse(string $key, RateLimitWindow $window, RateLimit $limit): void
    {
        $abuseThreshold = $this->config->get('rate_limiting.abuse_threshold', 5);
        
        $exceededCount = $this->cache->increment(
            "rate_limit_exceeded:{$key}",
            1,
            $this->config->get('rate_limiting.abuse_window', 3600)
        );

        if ($exceededCount >= $abuseThreshold) {
            $this->handlePotentialAbuse($key, $window, $limit);
        }
    }

    private function handlePotentialAbuse(string $key, RateLimitWindow $window, RateLimit $limit): void
    {
        // Log critical security event
        $this->auditLogger->logSecurityEvent(
            new SecurityEvent(
                SecurityEventType::POTENTIAL_ABUSE,
                "Potential abuse detected for key: {$key}",
                SecurityLevel::CRITICAL,
                [
                    'key' => $key,
                    'attempts' => $window->attempts,
                    'limit' => $limit->maxAttempts,
                    'window' => $limit->window
                ]
            )
        );

        // Notify security team
        event(new PotentialAbuseDetected($key, $window, $limit));
    }

    private function buildCacheKey(string $key): string
    {
        return "rate_limit:{$key}";
    }
}

interface RateLimitInterface
{
    public function checkLimit(string $key, RateLimit $limit): bool;
    public function checkMultiLimit(array $limits): bool;
    public function getRemainingAttempts(string $key, RateLimit $limit): int;
    public function getResetTime(string $key, RateLimit $limit): int;
}

class RateLimit
{
    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $window
    ) {}
}

class RateLimitWindow
{
    public function __construct(
        public int $attempts,
        public int $startedAt,
        public int $expiresAt
    ) {}

    public function increment(): void
    {
        $this->attempts++;
    }

    public function isExpired(): bool
    {
        return time() > $this->expiresAt;
    }
}
