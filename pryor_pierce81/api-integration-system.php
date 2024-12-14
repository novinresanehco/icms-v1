<?php

namespace App\Core\API;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

class APIManager implements APIInterface
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected RateLimiter $limiter;
    protected ResponseFormatter $formatter;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        RateLimiter $limiter,
        ResponseFormatter $formatter,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->limiter = $limiter;
        $this->formatter = $formatter;
        $this->config = $config;
    }

    public function handle(APIRequest $request): APIResponse
    {
        return $this->security->executeCriticalOperation(function() use ($request) {
            $this->validateRequest($request);
            
            if (!$this->limiter->check($request)) {
                throw new RateLimitExceededException();
            }

            if ($response = $this->getCachedResponse($request)) {
                return $response;
            }

            try {
                $response = $this->processRequest($request);
                $this->cacheResponse($request, $response);
                return $response;
                
            } catch (\Exception $e) {
                throw $this->handleException($e, $request);
            } finally {
                $this->logRequest($request, $response ?? null);
            }
        });
    }

    protected function validateRequest(APIRequest $request): void
    {
        $this->validator->validate($request->all(), [
            'method' => 'required|string|in:GET,POST,PUT,DELETE',
            'path' => 'required|string',
            'version' => 'required|string|in:' . implode(',', $this->config['supported_versions']),
            'params' => 'array'
        ]);

        if (!$this->isValidEndpoint($request->path, $request->method)) {
            throw new InvalidEndpointException();
        }
    }

    protected function processRequest(APIRequest $request): APIResponse
    {
        $handler = $this->resolveHandler($request);
        $result = $handler->handle($request);
        
        return $this->formatter->format(
            $result,
            $request->version,
            $this->getResponseFormat($request)
        );
    }

    protected function getCachedResponse(APIRequest $request): ?APIResponse
    {
        if (!$this->isCacheable($request)) {
            return null;
        }

        $cacheKey = $this->generateCacheKey($request);
        return $this->cache->get($cacheKey);
    }

    protected function cacheResponse(APIRequest $request, APIResponse $response): void
    {
        if (!$this->isCacheable($request)) {
            return;
        }

        $cacheKey = $this->generateCacheKey($request);
        $ttl = $this->getCacheTTL($request);
        
        $this->cache->set($cacheKey, $response, $ttl);
    }

    protected function resolveHandler(APIRequest $request): RequestHandler
    {
        $handlerClass = $this->config['handlers'][$request->path] ?? null;
        
        if (!$handlerClass || !class_exists($handlerClass)) {
            throw new HandlerNotFoundException();
        }

        return new $handlerClass($this->security);
    }

    protected function isValidEndpoint(string $path, string $method): bool
    {
        return isset($this->config['endpoints'][$path]) &&
               in_array($method, $this->config['endpoints'][$path]['methods']);
    }

    protected function isCacheable(APIRequest $request): bool
    {
        return $request->method === 'GET' && 
               !empty($this->config['endpoints'][$request->path]['cache_ttl']);
    }

    protected function getCacheTTL(APIRequest $request): int
    {
        return $this->config['endpoints'][$request->path]['cache_ttl'] ?? 
               $this->config['default_cache_ttl'];
    }

    protected function generateCacheKey(APIRequest $request): string
    {
        return 'api:' . md5(serialize([
            'path' => $request->path,
            'method' => $request->method,
            'params' => $request->params,
            'version' => $request->version
        ]));
    }

    protected function getResponseFormat(APIRequest $request): string
    {
        return $request->header('Accept') === 'application/xml' ? 'xml' : 'json';
    }

    protected function handleException(\Exception $e, APIRequest $request): APIException
    {
        Log::error('API Error', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'request' => [
                'path' => $request->path,
                'method' => $request->method,
                'params' => $request->params,
                'version' => $request->version
            ],
            'trace' => $e->getTraceAsString()
        ]);

        return new APIException(
            'Internal Server Error',
            500,
            $e
        );
    }

    protected function logRequest(APIRequest $request, ?APIResponse $response): void
    {
        Log::info('API Request', [
            'path' => $request->path,
            'method' => $request->method,
            'params' => $request->params,
            'version' => $request->version,
            'response_status' => $response ? $response->getStatusCode() : null,
            'response_time' => $response ? $response->getProcessingTime() : null,
            'user_id' => auth()->id(),
            'ip' => request()->ip()
        ]);
    }
}
