<?php

namespace App\Core\Audit\Middleware;

class LoggingMiddleware
{
    private LoggerInterface $logger;
    private array $config;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function handle(Request $request, \Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $start) * 1000;

        $this->log($request, $response, $duration);

        return $response;
    }

    private function log(Request $request, Response $response, float $duration): void
    {
        if ($this->shouldLog($request)) {
            $this->logger->info('Request processed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->status(),
                'duration' => $duration
            ]);
        }
    }

    private function shouldLog(Request $request): bool
    {
        if (isset($this->config['exclude_paths'])) {
            foreach ($this->config['exclude_paths'] as $path) {
                if ($request->is($path)) {
                    return false;
                }
            }
        }

        return true;
    }
}

class MetricsMiddleware
{
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(MetricsCollector $metrics, array $config = [])
    {
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function handle(Request $request, \Closure $next)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $response = $next($request);

        $duration = (microtime(true) - $startTime) * 1000;
        $memoryUsed = memory_get_usage() - $startMemory;

        $this->recordMetrics($request, $response, $duration, $memoryUsed);

        return $response;
    }

    private function recordMetrics(Request $request, Response $response, float $duration, int $memoryUsed): void
    {
        $tags = [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->status()
        ];

        $this->metrics->timing('request.duration', $duration, $tags);
        $this->metrics->gauge('request.memory', $memoryUsed, $tags);
        $this->metrics->increment('request.total', 1, $tags);
    }
}

class ValidationMiddleware
{
    private ValidatorInterface $validator;
    private array $rules;

    public function __construct(ValidatorInterface $validator, array $rules)
    {
        $this->validator = $validator;
        $this->rules = $rules;
    }

    public function handle(Request $request, \Closure $next)
    {
        $data = array_merge(
            $request->query->all(),
            $request->request->all()
        );

        $result = $this->validator->validate($data, $this->rules);

        if (!$result->isValid()) {
            throw new ValidationException($result->getErrors());
        }

        return $next($request);
    }
}

class SecurityMiddleware
{
    private SecurityChecker $checker;
    private LoggerInterface $logger;

    public function __construct(SecurityChecker $checker, LoggerInterface $logger)
    {
        $this->checker = $checker;
        $this->logger = $logger;
    }

    public function handle(Request $request, \Closure $next)
    {
        $checkResult = $this->checker->checkRequest($request);

        if (!$checkResult->isPassed()) {
            $this->logger->warning('Security check failed', [
                'issues' => $checkResult->getIssues(),
                'ip' => $request->getClientIp()
            ]);

            throw new SecurityException('Security check failed');
        }

        return $next($request);
    }
}

class CacheMiddleware
{
    private CacheInterface $cache;
    private array $config;

    public function __construct(CacheInterface $cache, array $config = [])
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->shouldCache($request)) {
            return $next($request);
        }

        $cacheKey = $this->getCacheKey($request);

        if ($response = $this->cache->get($cacheKey)) {
            return $response;
        }

        $response = $next($request);

        $this->cache->set(
            $cacheKey,
            $response,
            $this->config['ttl'] ?? 3600
        );

        return $response;
    }

    private function shouldCache(Request $request): bool
    {
        return $request->isMethodCacheable() &&
               !$request->headers->has('Authorization');
    }

    private function getCacheKey(Request $request): string
    {
        return md5($request->getUri());
    }
}
