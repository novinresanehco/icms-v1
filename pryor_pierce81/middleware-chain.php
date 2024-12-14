<?php

namespace App\Core\Middleware;

class CriticalMiddlewareChain
{
    private $middleware = [];
    private $monitor;

    public function process(Request $request, callable $handler): Response
    {
        $chain = array_reduce(
            array_reverse($this->middleware),
            fn($next, $middleware) => fn($request) => $middleware->process($request, $next),
            $handler
        );

        try {
            return $chain($request);
        } catch (\Exception $e) {
            $this->monitor->logMiddlewareFailure($e);
            throw $e;
        }
    }

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}

class SecurityMiddleware implements MiddlewareInterface
{
    private $security;
    
    public function process(Request $request, callable $next): Response
    {
        // Validate request security
        $this->security->validateRequest($request);
        
        return $next($request);
    }
}

class ValidationMiddleware implements MiddlewareInterface
{
    private $validator;
    
    public function process(Request $request, callable $next): Response
    {
        // Validate request data
        $this->validator->validate($request);
        
        return $next($request);
    }
}
