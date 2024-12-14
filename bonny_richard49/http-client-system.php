<?php

namespace App\Core\Http\Contracts;

interface HttpClientInterface
{
    public function request(string $method, string $url, array $options = []): Response;
    public function get(string $url, array $query = []): Response;
    public function post(string $url, array $data = []): Response;
    public function put(string $url, array $data = []): Response;
    public function delete(string $url): Response;
    public function withHeaders(array $headers): self;
    public function withMiddleware(callable $middleware): self;
}

namespace App\Core\Http\Services;

class HttpClient implements HttpClientInterface
{
    protected Config $config;
    protected array $middleware = [];
    protected array $headers = [];
    protected RetryHandler $retryHandler;
    protected CircuitBreaker $circuitBreaker;
    protected ResponseCache $cache;
    protected MetricsCollector $metrics;

    public function __construct(
        Config $config,
        RetryHandler $retryHandler,
        CircuitBreaker $circuitBreaker,
        ResponseCache $cache,
        MetricsCollector $metrics
    ) {
        $this->config = $config;
        $this->retryHandler = $retryHandler;
        $this->circuitBreaker = $circuitBreaker;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        // Check circuit breaker
        $this->circuitBreaker->checkState($url);

        // Start metrics
        $startTime = microtime(true);

        try {
            // Build request
            $request = $this->buildRequest($method, $url, $options);

            // Apply middleware
            $response = $this->sendWithMiddleware($request);

            // Record success metrics
            $this->recordSuccess($url, $startTime);

            return $response;
        } catch (HttpException $e) {
            // Record failure metrics
            $this->recordFailure($url, $startTime, $e);

            // Handle retry if applicable
            if ($this->retryHandler->shouldRetry($e)) {
                return $this->retryRequest($method, $url, $options);
            }

            throw $e;
        }
    }

    public function get(string $url, array $query = []): Response
    {
        // Check cache first
        if ($this->shouldUseCache($url)) {
            if ($cached = $this->cache->get($url, $query)) {
                return $cached;
            }
        }

        $response = $this->request('GET', $url, [
            'query' => $query
        ]);

        // Cache response if applicable
        if ($this->shouldUseCache($url)) {
            $this->cache->put($url, $query, $response);
        }

        return $response;
    }

    public function post(string $url, array $data = []): Response
    {
        return $this->request('POST', $url, [
            'json' => $data
        ]);
    }

    public function put(string $url, array $data = []): Response
    {
        return $this->request('PUT', $url, [
            'json' => $data
        ]);
    }

    public function delete(string $url): Response
    {
        return $this->request('DELETE', $url);
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function withMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    protected function buildRequest(string $method, string $url, array $options): Request
    {
        return new Request([
            'method' => $method,
            'url' => $url,
            'headers' => array_merge($this->headers, $options['headers'] ?? []),
            'query' => $options['query'] ?? [],
            'json' => $options['json'] ?? null,
            'timeout' => $options['timeout'] ?? $this->config->get('http.timeout'),
            'verify' => $options['verify'] ?? true
        ]);
    }

    protected function sendWithMiddleware(Request $request): Response
    {
        $middleware = array_reverse($this->middleware);
        
        $pipeline = array_reduce($middleware, function ($next, $middleware) {
            return function ($request) use ($next, $middleware) {
                return $middleware($request, $next);
            };
        }, function ($request) {
            return $this->send($request);
        });

        return $pipeline($request);
    }

    protected function send(Request $request): Response
    {
        // Actually send the HTTP request
        // Implementation depends on the underlying HTTP client library
    }

    protected function recordSuccess(string $url, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->increment('http_client.requests.success', 1, ['url' => $url]);
        $this->metrics->timing('http_client.requests.duration', $duration, ['url' => $url]);
    }

    protected function recordFailure(string $url, float $startTime, \Exception $e): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->increment('http_client.requests.failure', 1, [
            'url' => $url,
            'error' => get_class($e)
        ]);
        $this->metrics->timing('http_client.requests.duration', $duration, ['url' => $url]);
    }

    protected function shouldUseCache(string $url): bool
    {
        return $this->config->get('http.cache.enabled', false) &&
               !in_array($url, $this->config->get('http.cache.excluded_urls', []));
    }
}

namespace App\Core\Http\Services;

class RetryHandler
{
    protected int $maxAttempts;
    protected array $retryableStatuses;
    protected array $retryableExceptions;
    protected BackoffStrategy $backoff;

    public function shouldRetry(\Exception $exception): bool
    {
        if ($this->attempts >= $this->maxAttempts) {
            return false;
        }

        if ($exception instanceof HttpException) {
            return $this->isRetryableStatus($exception->getResponse()->getStatusCode());
        }

        return $this->isRetryableException($exception);
    }

    protected function isRetryableStatus(int $status): bool
    {
        return in_array($status, $this->retryableStatuses);
    }

    protected function isRetryableException(\Exception $e): bool
    {
        foreach ($this->retryableExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }
        return false;
    }

    public function getDelay(int $attempt): int
    {
        return $this->backoff->getDelay($attempt);
    }
}

namespace App\Core\Http\Services;

class CircuitBreaker
{
    protected array $failures = [];
    protected array $thresholds;
    protected int $resetTimeout;

    public function checkState(string $url): void
    {
        $service = $this->getServiceFromUrl($url);
        
        if ($this->isOpen($service)) {
            if ($this->shouldReset($service)) {
                $this->reset($service);
            } else {
                throw new CircuitOpenException("Circuit breaker is open for service: {$service}");
            }
        }
    }

    public function recordFailure(string $url): void
    {
        $service = $this->getServiceFromUrl($url);
        
        if (!isset($this->failures[$service])) {
            $this->failures[$service] = [
                'count' => 0,
                'last_failure' => null
            ];
        }

        $this->failures[$service]['count']++;
        $this->failures[$service]['last_failure'] = time();
    }

    protected function isOpen(string $service): bool
    {
        if (!isset($this->failures[$service])) {
            return false;
        }

        return $this->failures[$service]['count'] >= $this->thresholds[$service];
    }

    protected function shouldReset(string $service): bool
    {
        $lastFailure = $this->failures[$service]['last_failure'];
        return (time() - $lastFailure) >= $this->resetTimeout;
    }

    protected function reset(string $service): void
    {
        unset($this->failures[$service]);
    }

    protected function getServiceFromUrl(string $url): string
    {
        return parse_url($url, PHP_URL_HOST);
    }
}

namespace App\Core\Http\Services;

class ResponseCache
{
    protected CacheManager $cache;
    protected array $config;

    public function get(string $url, array $query = []): ?Response
    {
        $key = $this->getCacheKey($url, $query);
        return $this->cache->get($key);
    }

    public function put(string $url, array $query, Response $response): void
    {
        if (!$this->isCacheable($response)) {
            return;
        }

        $key = $this->getCacheKey($url, $query);
        $ttl = $this->getTtl($url);

        $this->cache->put($key, $response, $ttl);
    }

    protected function getCacheKey(string $url, array $query): string
    {
        return 'http_client:' . md5($url . serialize($query));
    }

    protected function isCacheable(Response $response): bool
    {
        return $response->getStatusCode() === 200 &&
               !$response->hasHeader('Cache-Control', 'no-store');
    }

    protected function getTtl(string $url): int
    {
        foreach ($this->config['ttl_rules'] as $pattern => $ttl) {
            if (preg_match($pattern, $url)) {
                return $ttl;
            }
        }

        return $this->config['default_ttl'];
    }
}

namespace App\Core\Http\Middleware;

class LoggingMiddleware
{
    protected LoggerInterface $logger;

    public function __invoke(Request $request, callable $next)
    {
        $startTime = microtime(true);

        try {
            $response = $next($request);

            $this->logSuccess($request, $response, $startTime);

            return $response;
        } catch (\Exception $e) {
            $this->logError($request, $e, $startTime);
            throw $e;
        }
    }

    protected function logSuccess(Request $request, Response $response, float $startTime): void
    {
        $duration = microtime(true) - $startTime;

        $this->logger->info('HTTP request completed', [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
            'status' => $response->getStatusCode(),
            'duration' => $duration
        ]);
    }

    protected function logError(Request $request, \Exception $e, float $startTime): void
    {
        $duration = microtime(true) - $startTime;

        $this->logger->error('HTTP request failed', [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
            'error' => $e->getMessage(),
            'duration' => $duration
        ]);
    }
}

