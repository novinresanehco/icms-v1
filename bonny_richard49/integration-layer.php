<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Integration\DTO\{IntegrationRequest, IntegrationResponse};
use App\Core\Exceptions\IntegrationException;
use Illuminate\Support\Facades\{Cache, Log, Http};

class IntegrationService implements IntegrationInterface
{
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->config = config('integration');
    }

    public function executeRequest(IntegrationRequest $request): IntegrationResponse
    {
        $startTime = microtime(true);
        $context = $this->createSecurityContext($request);
        
        try {
            // Validate request and check security
            $this->validateRequest($request);
            $this->security->validateCriticalOperation($context);
            
            // Check rate limits
            $this->checkRateLimit($request);
            
            // Try cache if applicable
            if ($request->isCacheable()) {
                $cached = $this->getFromCache($request);
                if ($cached) {
                    return $cached;
                }
            }
            
            // Execute request with retry mechanism
            $response = $this->executeWithRetry($request);
            
            // Cache response if applicable
            if ($request->isCacheable()) {
                $this->cacheResponse($request, $response);
            }
            
            // Record metrics
            $this->recordMetrics($request, microtime(true) - $startTime);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->handleFailure($e, $request, $context);
            throw $e;
        }
    }

    protected function executeWithRetry(IntegrationRequest $request): IntegrationResponse
    {
        $attempts = 0;
        $maxAttempts = $this->config['retry_attempts'] ?? 3;
        
        while ($attempts < $maxAttempts) {
            try {
                return $this->execute($request);
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts === $maxAttempts || !$this->shouldRetry($e)) {
                    throw $e;
                }
                $this->waitForRetry($attempts);
            }
        }
    }

    protected function execute(IntegrationRequest $request): IntegrationResponse
    {
        $options = $this->prepareRequestOptions($request);
        
        $response = Http::withOptions($options)
            ->withHeaders($request->getHeaders())
            ->send(
                $request->getMethod(),
                $request->getUrl(),
                $request->getPayload()
            );
            
        if (!$response->successful()) {
            throw new IntegrationException(
                "Integration request failed with status {$response->status()}"
            );
        }
        
        return new IntegrationResponse(
            $response->json(),
            $response->status(),
            $response->headers()
        );
    }

    protected function validateRequest(IntegrationRequest $request): void
    {
        if (!$this->validator->validateIntegrationRequest($request)) {
            throw new ValidationException('Invalid integration request');
        }
    }

    protected function checkRateLimit(IntegrationRequest $request): void
    {
        $key = "integration_rate:{$request->getService()}";
        $limit = $this->config['rate_limits'][$request->getService()] ?? 100;
        
        $current = Cache::increment($key);
        if ($current === 1) {
            Cache::expire($key, 60);
        }
        
        if ($current > $limit) {
            throw new IntegrationException('Rate limit exceeded');
        }
    }

    protected function getFromCache(IntegrationRequest $request): ?IntegrationResponse
    {
        $key = $this->getCacheKey($request);
        return $this->cache->get($key);
    }

    protected function cacheResponse(IntegrationRequest $request, IntegrationResponse $response): void
    {
        $key = $this->getCacheKey($request);
        $ttl = $request->getCacheTTL() ?? $this->config['default_cache_ttl'] ?? 3600;
        
        $this->cache->put($key, $response, $ttl);
    }

    protected function getCacheKey(IntegrationRequest $request): string
    {
        return sprintf(
            'integration:%s:%s',
            $request->getService(),
            md5(serialize($request))
        );
    }

    protected function prepareRequestOptions(IntegrationRequest $request): array
    {
        return [
            'timeout' => $request->getTimeout() ?? $this->config['default_timeout'] ?? 30,
            'verify' => $this->config['verify_ssl'] ?? true,
            'http_errors' => false,
            'connect_timeout' => $this->config['connect_timeout'] ?? 10
        ];
    }

    protected function shouldRetry(\Exception $e): bool
    {
        return in_array(get_class($e), $this->config['retryable_exceptions'] ?? []);
    }

    protected function waitForRetry(int $attempt): void
    {
        $delay = min(
            pow(2, $attempt) * ($this->config['base_retry_delay'] ?? 100),
            $this->config['max_retry_delay'] ?? 1000
        );
        
        usleep($delay * 1000);
    }

    protected function recordMetrics(IntegrationRequest $request, float $duration): void
    {
        $this->metrics->recordIntegrationRequest(
            $request->getService(),
            $duration,
            [
                'method' => $request->getMethod(),
                'cache_hit' => $request->isCacheable(),
                'success' => true
            ]
        );
    }

    protected function handleFailure(\Exception $e, IntegrationRequest $request, $context): void
    {
        Log::error('Integration request failed', [
            'service' => $request->getService(),
            'url' => $request->getUrl(),
            'method' => $request->getMethod(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordIntegrationRequest(
            $request->getService(),
            0,
            [
                'method' => $request->getMethod(),
                'error' => get_class($e),
                'success' => false
            ]
        );
    }

    protected function createSecurityContext(IntegrationRequest $request): SecurityContext
    {
        return new SecurityContext([
            'service' => $request->getService(),
            'operation' => $request->getOperation(),
            'ip' => request()->ip(),
            'user' => auth()->user()
        ]);
    }
}
