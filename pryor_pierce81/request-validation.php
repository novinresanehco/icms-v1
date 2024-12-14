<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Monitor\SystemMonitor;
use Illuminate\Support\Facades\{Cache, Log};

final class RequestValidator
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private RateLimiter $limiter;
    private array $rules;

    public function validateRequest(Request $request): void
    {
        $requestId = uniqid('req_', true);

        try {
            $this->checkRateLimit($request);
            $this->validateHeaders($request);
            $this->validateInput($request);
            $this->security->validateAuthentication($request);
            
        } catch (\Throwable $e) {
            $this->handleValidationFailure($e, $requestId, $request);
            throw $e;
        }
    }

    private function checkRateLimit(Request $request): void
    {
        if (!$this->limiter->check($request)) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function validateHeaders(Request $request): void
    {
        $required = ['Authorization', 'X-Security-Token', 'X-Request-ID'];
        
        foreach ($required as $header) {
            if (!$request->hasHeader($header)) {
                throw new ValidationException("Missing required header: $header");
            }
        }
    }

    private function validateInput(Request $request): void
    {
        $data = $request->all();
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException("Invalid field: $field");
            }
        }
    }
}

final class RateLimiter
{
    private array $limits = [
        'global' => [100, 60],    // 100 requests per minute
        'user' => [1000, 3600],   // 1000 requests per hour
        'ip' => [10000, 86400]    // 10000 requests per day
    ];

    public function check(Request $request): bool
    {
        foreach ($this->limits as $type => $limit) {
            if (!$this->checkLimit($request, $type, ...$limit)) {
                return false;
            }
        }
        return true;
    }

    private function checkLimit(Request $request, string $type, int $max, int $period): bool
    {
        $key = $this->getLimitKey($request, $type);
        $current = (int)Cache::get($key, 0);
        
        if ($current >= $max) {
            return false;
        }

        Cache::increment($key);
        if ($current === 0) {
            Cache::put($key, 1, $period);
        }

        return true;
    }

    private function getLimitKey(Request $request, string $type): string
    {
        return match($type) {
            'global' => 'rate_limit:global',
            'user' => 'rate_limit:user:' . $request->user()?->id,
            'ip' => 'rate_limit:ip:' . $request->ip(),
            default => throw new \InvalidArgumentException("Invalid limit type: $type")
        };
    }
}

final class RequestMonitor 
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function recordRequest(Request $request, ?Response $response = null): void
    {
        $this->metrics->record([
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response?->status(),
            'duration' => $this->getRequestDuration(),
            'memory' => memory_get_usage(true)
        ]);

        if ($this->shouldAlert($response)) {
            $this->alerts->trigger('REQUEST_ALERT', [
                'request' => $request,
                'response' => $response
            ]);
        }
    }

    private function shouldAlert(?Response $response): bool
    {
        return $response && (
            $response->status() >= 500 ||
            $response->status() === 429 ||
            $this->getRequestDuration() > 1000
        );
    }
}

class ValidationException extends \Exception {}
class RateLimitException extends \Exception {}
