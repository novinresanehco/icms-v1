<?php

namespace App\Core\Router;

class RouterManager implements RouterInterface
{
    private RouteRegistry $registry;
    private SecurityManager $security;
    private ValidationService $validator;
    private MiddlewareManager $middleware;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function __construct(
        RouteRegistry $registry,
        SecurityManager $security,
        ValidationService $validator,
        MiddlewareManager $middleware,
        CacheManager $cache,
        AuditLogger $logger
    ) {
        $this->registry = $registry;
        $this->security = $security;
        $this->validator = $validator;
        $this->middleware = $middleware;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function dispatch(Request $request): Response
    {
        $requestId = uniqid('req_', true);
        
        try {
            $this->validateRequest($request);
            $this->security->validateRequestContext($request);

            $route = $this->resolveRoute($request);
            $this->validateRoute($route, $request);

            $response = $this->processRoute($requestId, $route, $request);
            $this->validateResponse($response);

            $this->logger->logRequest($requestId, $request, $response);
            return $response;

        } catch (\Exception $e) {
            return $this->handleRouteFailure($requestId, $request, $e);
        }
    }

    private function validateRequest(Request $request): void
    {
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid request format');
        }

        if (!$this->security->validateRequestMethod($request->getMethod())) {
            throw new SecurityException('Invalid request method');
        }

        if ($this->security->isBlacklistedIP($request->getClientIp())) {
            throw new SecurityException('Access denied');
        }
    }

    private function resolveRoute(Request $request): Route
    {
        $cacheKey = $this->generateRouteCacheKey($request);

        return $this->cache->remember($cacheKey, 3600, function() use ($request) {
            $route = $this->registry->match($request);

            if (!$route) {
                throw new RouteNotFoundException('Route not found');
            }

            return $route;
        });
    }

    private function validateRoute(Route $route, Request $request): void
    {
        if (!$this->security->validateRouteAccess($route, $request)) {
            throw new AccessDeniedException('Route access denied');
        }

        if (!$this->validator->validateRouteParameters($route, $request)) {
            throw new ValidationException('Invalid route parameters');
        }

        if ($route->isDeprecated() && !$this->security->allowDeprecatedRoute($route)) {
            throw new DeprecationException('Route is deprecated');
        }
    }

    private function processRoute(string $requestId, Route $route, Request $request): Response
    {
        $this->startTransaction($requestId);

        try {
            $processedRequest = $this->processMiddleware($route, $request);
            $response = $this->executeRoute($route, $processedRequest);
            
            $this->commitTransaction($requestId);
            return $response;

        } catch (\Exception $e) {
            $this->rollbackTransaction($requestId);
            throw $e;
        }
    }

    private function processMiddleware(Route $route, Request $request): Request
    {
        $middlewares = array_merge(
            $this->middleware->getGlobalMiddleware(),
            $route->getMiddleware()
        );

        foreach ($middlewares as $middleware) {
            $request = $middleware->process($request);
        }

        return $request;
    }

    private function executeRoute(Route $route, Request $request): Response
    {
        $controller = $this->registry->resolveController($route);
        $this->validateController($controller);

        $parameters = $this->resolveParameters($route, $request);
        
        return $controller->{$route->getAction()}(...$parameters);
    }

    private function validateResponse(Response $response): void
    {
        if (!$this->validator->validateResponse($response)) {
            throw new ValidationException('Invalid response format');
        }

        if (!$this->security->validateResponseHeaders($response)) {
            throw new SecurityException('Invalid response headers');
        }
    }

    private function handleRouteFailure(string $requestId, Request $request, \Exception $e): Response
    {
        $this->logger->logRouteFailure($requestId, $request, $e);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($requestId, $e);
        }

        return $this->createErrorResponse($e);
    }

    private function startTransaction(string $requestId): void
    {
        DB::beginTransaction();
        $this->logger->logTransactionStart($requestId);
    }

    private function commitTransaction(string $requestId): void
    {
        DB::commit();
        $this->logger->logTransactionCommit($requestId);
    }

    private function rollbackTransaction(string $requestId): void
    {
        DB::rollBack();
        $this->logger->logTransactionRollback($requestId);
    }

    private function generateRouteCacheKey(Request $request): string
    {
        return sprintf(
            'route:%s:%s:%s',
            $request->getMethod(),
            $request->getPathInfo(),
            hash('xxh3', serialize($request->query->all()))
        );
    }

    private function validateController($controller): void
    {
        if (!$controller instanceof ControllerInterface) {
            throw new ControllerException('Invalid controller implementation');
        }

        if (!$this->security->validateController($controller)) {
            throw new SecurityException('Controller validation failed');
        }
    }

    private function resolveParameters(Route $route, Request $request): array
    {
        $parameters = $route->bindParameters($request);
        
        foreach ($parameters as $name => $value) {
            if (!$this->validator->validateParameter($name, $value, $route->getParameterRules())) {
                throw new ValidationException("Invalid parameter: {$name}");
            }
        }

        return $parameters;
    }

    private function createErrorResponse(\Exception $e): Response
    {
        $status = $this->determineStatusCode($e);
        $headers = $this->security->getSecureHeaders();

        return new Response(
            $this->formatErrorResponse($e),
            $status,
            $headers
        );
    }

    private function determineStatusCode(\Exception $e): int
    {
        return match (get_class($e)) {
            RouteNotFoundException::class => 404,
            AccessDeniedException::class => 403,
            ValidationException::class => 422,
            SecurityException::class => 403,
            default => 500
        };
    }

    private function formatErrorResponse(\Exception $e): string
    {
        return json_encode([
            'error' => [
                'type' => class_basename($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]
        ]);
    }
}
