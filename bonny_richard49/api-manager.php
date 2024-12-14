<?php

namespace App\Core\API;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\{Route, DB, Log};

class APIManager implements APIManagerInterface
{
    private SecurityManager $security;
    private RateLimiter $limiter;
    private ValidationService $validator;
    private CacheManager $cache;
    
    public function registerRoute(string $method, string $path, callable $handler): void
    {
        Route::match([$method], $path, function(Request $request) use ($handler) {
            return $this->executeHandler($request, $handler);
        })->middleware([
            'api',
            'auth:sanctum',
            RateLimitMiddleware::class
        ]);
    }

    public function executeHandler(Request $request, callable $handler): Response
    {
        return $this->security->executeCriticalOperation(
            new APIOperationHandler($request, $handler, $this->validator),
            new SecurityContext([
                'operation' => 'api.handle',
                'path' => $request->path(),
                'method' => $request->method()
            ])
        );
    }

    public function validateRequest(Request $request): bool
    {
        // Validate rate limits
        if (!$this->limiter->check($request)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        // Validate authentication
        if (!$this->validateAuthentication($request)) {
            throw new AuthenticationException('Invalid authentication');
        }

        // Validate authorization
        if (!$this->validateAuthorization($request)) {
            throw new AuthorizationException('Unauthorized access');
        }

        // Validate input
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid request data');
        }

        return true;
    }

    public function handleResponse(Response $response): Response
    {
        // Add security headers
        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ]);

        // Cache if cacheable
        if ($this->isCacheableResponse($response)) {
            $this->cacheResponse($response);
        }

        return $response;
    }

    private function validateAuthentication(Request $request): bool
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return false;
        }

        return $this->security->validateToken($token);
    }

    private function validateAuthorization(Request $request): bool
    {
        $user = $request->user();
        $permission = $this->getRequiredPermission($request);

        return $user && $this->security->validatePermissions($user, $permission);
    }

    private function isCacheableResponse(Response $response): bool
    {
        return $response->getStatusCode() === 200 
            && $response->headers->get('Cache-Control') !== 'no-cache'
            && in_array($response->headers->get('Content-Type'), [
                'application/json',
                'application/xml',
                'text/html'
            ]);
    }

    private function cacheResponse(Response $response): void
    {
        $key = $this->generateCacheKey($response);
        $ttl = $this->getCacheTTL($response);

        $this->cache->put($key, $response->getContent(), $ttl);
    }
}

class APIOperationHandler extends CriticalOperation
{
    private Request $request;
    private callable $handler;
    private ValidationService $validator;

    public function execute(): Response
    {
        DB::beginTransaction();

        try {
            // Validate request
            $this->validateRequest();

            // Execute handler
            $result = ($this->handler)($this->request);

            // Validate response
            $this->validateResponse($result);

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateRequest(): void
    {
        $rules = $this->getValidationRules();
        
        if (!empty($rules)) {
            $this->validator->validate($this->request->all(), $rules);
        }
    }

    private function validateResponse($response): void
    {
        if (!$response instanceof Response) {
            throw new APIException('Invalid response type');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 500) {
            throw new APIException('Invalid response status');
        }
    }

    private function getValidationRules(): array
    {
        $route = $this->request->route();
        return $route->getAction('validation') ?? [];
    }
}

class RateLimiter 
{
    private CacheManager $cache;
    private array $config;

    public function check(Request $request): bool
    {
        $key = $this->getKey($request);
        $limit = $this->getLimit($request);
        $window = $this->getWindow($request);

        $current = $this->cache->increment($key);
        
        if ($current === 1) {
            $this->cache->put($key, 1, $window);
        }

        return $current <= $limit;
    }

    private function getKey(Request $request): string
    {
        return sprintf(
            'ratelimit:%s:%s:%s',
            $request->ip(),
            $request->path(),
            $request->user()?->id ?? 'guest'
        );
    }

    private function getLimit(Request $request): int
    {
        if ($request->user()?->hasRole('admin')) {
            return $this->config['limits']['admin'];
        }

        return $this->config['limits']['default'];
    }

    private function getWindow(Request $request): int
    {
        return $this->config['window'] ?? 60;
    }
}

class ValidationService
{
    private array $rules = [
        'content.create' => [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ],
        'content.update' => [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ],
        'media.upload' => [
            'file' => 'required|file|mimes:jpeg,png,pdf|max:10240',
            'title' => 'required|string|max:255'
        ]
    ];

    public function validate(array $data, string $operation): void
    {
        if (!isset($this->rules[$operation])) {
            throw new ValidationException('Unknown operation');
        }

        $validator = Validator::make($data, $this->rules[$operation]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }

    public function validateRequest(Request $request): bool
    {
        // Validate headers
        if (!$this->validateHeaders($request)) {
            return false;
        }

        // Validate content type
        if (!$this->validateContentType($request)) {
            return false;
        }

        // Validate parameters
        if (!$this->validateParameters($request)) {
            return false;
        }

        return true;
    }

    private function validateHeaders(Request $request): bool
    {
        $required = ['Accept', 'Content-Type'];

        foreach ($required as $header) {
            if (!$request->header($header)) {
                return false;
            }
        }

        return true;
    }

    private function validateContentType(Request $request): bool
    {
        $contentType = $request->header('Content-Type');
        
        return in_array($contentType, [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data'
        ]);
    }

    private function validateParameters(Request $request): bool
    {
        $parameters = $request->all();
        
        foreach ($parameters as $key => $value) {
            if (!$this->isValidParameterName($key)) {
                return false;
            }

            if (!$this->isValidParameterValue($value)) {
                return false;
            }
        }

        return true;
    }

    private function isValidParameterName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9_]+$/', $name) === 1;
    }

    private function isValidParameterValue($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isValidParameterValue($item)) {
                    return false;
                }
            }
            return true;
        }

        if (is_string($value)) {
            return !preg_match('/[<>]/', $value);
        }

        return is_numeric($value) || is_bool($value) || is_null($value);
    }
}
