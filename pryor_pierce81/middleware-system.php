<?php

namespace App\Core\Routing;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\MiddlewareException;
use Psr\Log\LoggerInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MiddlewareManager implements MiddlewareManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $middlewareStack = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function processRequest(Request $request, array $middlewares): Response
    {
        $requestId = $this->generateRequestId();

        try {
            $this->security->validateSecureOperation('middleware:process', [
                'request_id' => $requestId
            ]);

            $this->validateMiddlewareStack($middlewares);
            $this->initializeStack($middlewares);

            return $this->executeMiddlewareStack($request);

        } catch (\Exception $e) {
            $this->handleMiddlewareFailure($requestId, 'process', $e);
            throw new MiddlewareException('Middleware processing failed', 0, $e);
        }
    }

    private function validateMiddlewareStack(array $middlewares): void
    {
        if (count($middlewares) > $this->config['max_middleware_stack']) {
            throw new MiddlewareException('Middleware stack too large');
        }

        foreach ($middlewares as $middleware) {
            if (!$this->isValidMiddleware($middleware)) {
                throw new MiddlewareException('Invalid middleware configuration');
            }
        }
    }

    private function initializeStack(array $middlewares): void
    {
        $this->middlewareStack = array_map(function($middleware) {
            return $this->instantiateMiddleware($middleware);
        }, $middlewares);
    }

    private function executeMiddlewareStack(Request $request): Response
    {
        $next = function($request) {
            return new Response();
        };

        foreach (array_reverse($this->middlewareStack) as $middleware) {
            $next = function($request) use ($middleware, $next) {
                return $middleware->handle($request, $next);
            };
        }

        return $next($request);
    }

    private function isValidMiddleware(array $middleware): bool
    {
        return isset($middleware['class']) &&
               class_exists($middleware['class']) &&
               is_subclass_of($middleware['class'], MiddlewareInterface::class);
    }

    private function instantiateMiddleware(array $middleware): MiddlewareInterface
    {
        return new $middleware['class']($this->security, $this->logger);
    }

    private function generateRequestId(): string
    {
        return uniqid('middleware_', true);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_middleware_stack' => 10,
            'timeout' => 30,
            'memory_limit' => '128M'
        ];
    }

    private function handleMiddlewareFailure(string $requestId, string $operation, \Exception $e): void
    {
        $this->logger->error('Middleware operation failed', [
            'request_id' => $requestId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
