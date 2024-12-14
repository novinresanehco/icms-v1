```php
namespace App\Core\Api\Routing;

class ApiRouter implements RouterInterface
{
    private SecurityManager $security;
    private RouteRegistry $registry;
    private MetricsCollector $metrics;

    public function route(Request $request): Response
    {
        return $this->metrics->track('api.routing', function() use ($request) {
            // Find route
            $route = $this->registry->match($request->method(), $request->path());
            
            if (!$route) {
                throw new RouteNotFoundException();
            }

            // Validate route access
            $this->security->validateRouteAccess($route, $request);
            
            // Execute route handler
            return $route->execute($request);
        });
    }
}

class RouteRegistry
{
    private array $routes = [];
    private ValidationService $validator;

    public function register(Route $route): void
    {
        $this->validator->validateRoute($route);
        $this->routes[] = $route;
    }

    public function match(string $method, string $path): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                return $route;
            }
        }
        return null;
    }
}
```
