<?php

namespace App\Core\Gateway\Models;

class Route extends Model
{
    protected $fillable = [
        'path',
        'method',
        'service',
        'timeout',
        'rate_limit',
        'transforms',
        'metadata'
    ];

    protected $casts = [
        'transforms' => 'array',
        'metadata' => 'array'
    ];
}

class Service extends Model
{
    protected $fillable = [
        'name',
        'url',
        'timeout',
        'retry_limit',
        'status',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];
}

namespace App\Core\Gateway\Services;

class GatewayManager
{
    private RouterService $router;
    private RequestHandler $handler;
    private ResponseTransformer $transformer;
    private RateLimiter $rateLimiter;

    public function handle(Request $request): Response
    {
        $route = $this->router->match($request);
        
        if (!$route) {
            throw new RouteNotFoundException();
        }

        $this->rateLimiter->check($route, $request);
        
        $response = $this->handler->handle($route, $request);
        
        return $this->transformer->transform($response, $route->transforms);
    }
}

class RouterService
{
    private RouteRepository $repository;
    private PathMatcher $matcher;

    public function match(Request $request): ?Route
    {
        $routes = $this->repository->findByMethod($request->method());
        
        foreach ($routes as $route) {
            if ($this->matcher->matches($route->path, $request->path())) {
                return $route;
            }
        }
        
        return null;
    }
}

class RequestHandler
{
    private ServiceRegistry $services;
    private HttpClient $client;
    private CircuitBreaker $circuitBreaker;

    public function handle(Route $route, Request $request): Response
    {
        $service = $this->services->get($route->service);
        
        if (!$this->circuitBreaker->isAvailable($service)) {
            throw new ServiceUnavailableException();
        }

        try {
            $response = $this->client->send(
                $this->buildRequest($route, $request, $service)
            );
            
            $this->circuitBreaker->success($service);
            return $response;
        } catch (\Exception $e) {
            $this->circuitBreaker->failure($service);
            throw $e;
        }
    }
}

class ResponseTransformer
{
    private array $transformers = [];

    public function register(string $name, callable $transformer): void
    {
        $this->transformers[$name] = $transformer;
    }

    public function transform(Response $response, array $transforms): Response
    {
        foreach ($transforms as $transform) {
            if (isset($this->transformers[$transform])) {
                $response = $this->transformers[$transform]($response);
            }
        }
        
        return $response;
    }
}

class CircuitBreaker
{
    private Cache $cache;
    private int $threshold;
    private int $timeout;

    public function isAvailable(Service $service): bool
    {
        $key = "circuit_breaker:{$service->id}";
        $failures = $this->cache->get($key, 0);
        
        return $failures < $this->threshold;
    }

    public function success(Service $service): void
    {
        $key = "circuit_breaker:{$service->id}";
        $this->cache->delete($key);
    }

    public function failure(Service $service): void
    {
        $key = "circuit_breaker:{$service->id}";
        $failures = $this->cache->increment($key);
        
        if ($failures >= $this->threshold) {
            $this->cache->put($key, $failures, $this->timeout);
        }
    }
}

class RateLimiter
{
    private Cache $cache;
    private int $window;

    public function check(Route $route, Request $request): void
    {
        if (!$route->rate_limit) {
            return;
        }

        $key = $this->getKey($route, $request);
        $current = $this->cache->increment($key);

        if ($current === 1) {
            $this->cache->put($key, 1, $this->window);
        }

        if ($current > $route->rate_limit) {
            throw new RateLimitExceededException();
        }
    }

    private function getKey(Route $route, Request $request): string
    {
        return "rate_limit:{$route->id}:{$request->ip()}";
    }
}

namespace App\Core\Gateway\Http\Controllers;

class GatewayController extends Controller
{
    private GatewayManager $gateway;

    public function handle(Request $request): Response
    {
        try {
            return $this->gateway->handle($request);
        } catch (RouteNotFoundException $e) {
            return response()->json(['error' => 'Not Found'], 404);
        } catch (ServiceUnavailableException $e) {
            return response()->json(['error' => 'Service Unavailable'], 503);
        } catch (RateLimitExceededException $e) {
            return response()->json(['error' => 'Too Many Requests'], 429);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}

namespace App\Core\Gateway\Console;

class RouteListCommand extends Command
{
    protected $signature = 'gateway:routes';

    public function handle(RouteRepository $repository): void
    {
        $routes = $repository->all();
        
        $this->table(
            ['Path', 'Method', 'Service', 'Rate Limit'],
            $routes->map(fn($route) => [
                $route->path,
                $route->method,
                $route->service,
                $route->rate_limit ?? 'None'
            ])
        );
    }
}
