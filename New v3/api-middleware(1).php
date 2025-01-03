<?php

namespace App\Http\Middleware\Api;

use App\Core\Security\{TokenManager, RateLimiter};
use App\Core\Monitoring\MetricsCollector;
use Illuminate\Http\Request;

class ApiMiddleware
{
    private TokenManager $tokens;
    private RateLimiter $limiter;
    private MetricsCollector $metrics;

    public function handle(Request $request, \Closure $next)
    {
        try {
            $startTime = microtime(true);

            $this->validateRequest($request);
            $this->checkRateLimit($request);
            
            $response = $next($request);
            
            $this->processResponse($response);
            $this->collectMetrics($request, $response, $startTime);

            return $response;

        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    protected function validateRequest(Request $request): void
    {
        // Token validation
        if ($token = $request->bearerToken()) {
            $token = $this->tokens->validate($token);
            $request->merge(['token' => $token]);
        }

        // Security headers
        if (!$request->secure() && config('api.force_https')) {
            throw new InsecureRequestException();
        }

        // Content validation
        if ($request->isMethod('POST') || $request->isMethod('PUT')) {
            $this->validateContent($request);
        }
    }

    protected function checkRateLimit(Request $request): void
    {
        $key = $this->getRateLimitKey($request);
        
        if (!$this->limiter->attempt($key)) {
            throw new RateLimitExceededException();
        }
    }

    protected function processResponse($response): void
    {
        // Add security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        
        // Cache control
        if (!$response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-cache, private');
        }

        // Compression
        if (in_array('gzip', $request->getEncodings())) {
            $response->headers->set('Content-Encoding', 'gzip');
        }
    }

    protected function collectMetrics(Request $request, $response, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record('api.request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->status(),
            'duration' => $duration,
            'user' => $request->user()?->id
        ]);

        if ($duration > config('api.slow_threshold')) {
            $this->metrics->recordSlowRequest($request, $duration);
        }
    }

    protected function handleError(\Exception $e)
    {
        $this->metrics->recordError($e);

        return response()->json([
            'error' => [
                'message' => $e->getMessage(),
                'type' => class_basename($e),
                'code' => $this->getErrorCode($e)
            ]
        ], $this->getStatusCode($e));
    }

    protected function validateContent(Request $request): void
    {
        $contentType = $request->header('Content-Type');

        if (!in_array($contentType, ['application/json', 'multipart/form-data'])) {
            throw new UnsupportedMediaTypeException();
        }

        if ($contentType === 'application/json') {
            if (!$this->isValidJson($request->getContent())) {
                throw new InvalidJsonException();
            }
        }
    }

    protected function getRateLimitKey(Request $request): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $request->ip(),
            $request->user()?->id ?? 'guest'
        );
    }

    protected function isValidJson(string $content): bool
    {
        json_decode($content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function getErrorCode(\Exception $e): int
    {
        return $e->getCode() ?: 500;
    }

    protected function getStatusCode(\Exception $e): int
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }

        return 500;
    }
}
