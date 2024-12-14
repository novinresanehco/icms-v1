<?php

namespace App\Http\Middleware;

use App\Core\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use Illuminate\Http\Request;

class RequestValidationMiddleware
{
    private ValidationService $validator;
    private AuditLogger $audit;
    private CacheManager $cache;

    private const MAX_REQUEST_SIZE = 10485760; // 10MB
    private const RATE_LIMIT = 1000; // requests per minute
    private const CACHE_TTL = 60; // 1 minute

    public function __construct(
        ValidationService $validator,
        AuditLogger $audit,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->cache = $cache;
    }

    public function handle(Request $request, \Closure $next)
    {
        try {
            // Validate request size
            $this->validateRequestSize($request);
            
            // Check rate limit
            $this->checkRateLimit($request);
            
            // Validate request format
            $this->validateRequestFormat($request);
            
            // Sanitize input
            $request = $this->sanitizeRequest($request);
            
            // Log validation
            $this->audit->logRequestValidation($request);
            
            return $next($request);
            
        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $request);
            throw $e;
        }
    }

    private function validateRequestSize(Request $request): void
    {
        $size = $request->header('Content-Length') ?: strlen($request->getContent());
        
        if ($size > self::MAX_REQUEST_SIZE) {
            throw new RequestValidationException('Request size exceeds limit');
        }
    }

    private function checkRateLimit(Request $request): void
    {
        $key = $this->getRateLimitKey($request);
        $count = (int) $this->cache->get($key, 0);
        
        if ($count >= self::RATE_LIMIT) {
            throw new RateLimitException('Rate limit exceeded');
        }

        $this->cache->increment($key, 1, self::CACHE_TTL);
    }

    private function validateRequestFormat(Request $request): void
    {
        if (!$this->validator->validateRequestFormat($request)) {
            throw new RequestValidationException('Invalid request format');
        }

        foreach ($request->all() as $key => $value) {
            if (!$this->validator->validateField($key, $value)) {
                throw new RequestValidationException("Invalid field: {$key}");
            }
        }
    }

    private function sanitizeRequest(Request $request): Request
    {
        $sanitized = [];
        
        foreach ($request->all() as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $request->merge($sanitized);
    }

    private function sanitizeValue($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeValue'], $value);
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function sanitizeString(string $value): string
    {
        // Remove NULL bytes
        $value = str_replace(chr(0), '', $value);
        
        // Convert special characters to HTML entities
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove potentially dangerous patterns
        $value = preg_replace('/javascript:/i', '', $value);
        
        return $value;
    }

    private function getRateLimitKey(Request $request): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $request->ip(),
            date('YmdHi')
        );
    }

    private function handleValidationFailure(\Exception $e, Request $request): void
    {
        $this->audit->logValidationFailure($e, [
            'request_id' => $request->header('x-request-id'),
            'uri' => $request->getRequestUri(),
            'method' => $request->getMethod(),
            'ip' => $request->ip(),
            'timestamp' => now()
        ]);
    }
}
