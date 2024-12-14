<?php

namespace App\Core\Http\Middleware;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;
use Closure;
use Illuminate\Http\Request;

class CriticalSecurityMiddleware
{
    private SecurityManager $security;
    private MonitoringService $monitor;

    public function handle(Request $request, Closure $next)
    {
        $operationId = $this->monitor->startOperation('request:validate');

        try {
            // Validate request integrity
            $this->validateRequest($request);

            // Security checks
            $this->performSecurityChecks($request);

            // Track request
            $response = $next($request);

            // Validate response
            $this->validateResponse($response);

            return $response;

        } catch (\Throwable $e) {
            $this->handleSecurityFailure($e, $request);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateRequest(Request $request): void
    {
        // Validate headers
        if (!$this->security->validateHeaders($request->headers->all())) {
            throw new SecurityException('Invalid request headers');
        }

        // Validate method
        if (!$this->security->validateMethod($request->method())) {
            throw new SecurityException('Invalid request method');
        }

        // Validate content
        if ($request->getContent()) {
            $this->security->validateContent($request->getContent());
        }
    }

    private function performSecurityChecks(Request $request): void
    {
        // Rate limiting
        if (!$this->security->checkRateLimit($request)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // IP validation
        if (!$this->security->validateIp($request->ip())) {
            throw new SecurityException('IP address blocked');
        }

        // Authentication check
        if (!$this->security->validateAuthentication($request)) {
            throw new SecurityException('Authentication failed');
        }
    }

    private function validateResponse($response): void
    {
        if (!$this->security->validateResponse($response)) {
            throw new SecurityException('Invalid response detected');
        }
    }

    private function handleSecurityFailure(\Throwable $e, Request $request): void
    {
        $this->monitor->recordFailure('security_middleware', [
            'error' => $e->getMessage(),
            'request' => [
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'ip' => $request->ip()
            ],
            'trace' => $e->getTraceAsString()
        ]);
    }
}
