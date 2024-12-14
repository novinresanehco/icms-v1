<?php

namespace App\Http\Middleware;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Http\Request;
use Closure;

class SecurityMiddleware
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function handle(Request $request, Closure $next)
    {
        $monitoringId = $this->monitor->startOperation('security_middleware');
        
        try {
            // Validate request
            $this->validateRequest($request);
            
            // Create security context
            $context = $this->createSecurityContext($request);
            
            // Validate security context
            if (!$this->security->validateSecurityContext($context)) {
                throw new SecurityContextException('Invalid security context');
            }
            
            // Enforce security policies
            $this->enforcePolicies($request, $context);
            
            // Set context for downstream
            $request->merge(['security_context' => $context]);
            
            $response = $next($request);
            
            // Validate response
            $this->validateResponse($response);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            return $this->handleSecurityException($e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateRequest(Request $request): void
    {
        // Validate headers
        $this->validateHeaders($request);
        
        // Validate content
        if ($request->isMethod('POST') || $request->isMethod('PUT')) {
            $this->validateContent($request);
        }
        
        // Validate query parameters
        if (!empty($request->query())) {
            $this->validateQueryParameters($request);
        }
    }

    private function validateHeaders(Request $request): void
    {
        $requiredHeaders = $this->config['required_headers'];
        
        foreach ($requiredHeaders as $header) {
            if (!$request->hasHeader($header)) {
                throw new SecurityHeaderException("Missing required header: {$header}");
            }
        }
    }

    private function validateContent(Request $request): void
    {
        $content = $request->getContent();
        
        if (empty($content)) {
            throw new SecurityContentException('Empty request content');
        }

        if (strlen($content) > $this->config['max_content_length']) {
            throw new SecurityContentException('Content length exceeds limit');
        }

        if ($request->isJson()) {
            $this->validateJsonContent($content);
        }
    }

    private function validateQueryParameters(Request $request): void
    {
        foreach ($request->query() as $key => $value) {
            if (!$this->isValidQueryParameter($key, $value)) {
                throw new SecurityQueryException('Invalid query parameter');
            }
        }
    }

    private function enforcePolicies(Request $request, SecurityContext $context): void
    {
        $policies = $this->getPoliciesForRoute($request->route());
        
        foreach ($policies as $policy) {
            if (!$this->security->enforcePolicy($policy, $context)) {
                throw new SecurityPolicyException("Policy violation: {$policy}");
            }
        }
    }

    private function handleSecurityException(\Exception $e): Response
    {
        $statusCode = $this->getStatusCodeForException($e);
        
        return response()->json([
            'error' => [
                'message' => $this->getPublicMessage($e),
                'code' => $statusCode
            ]
        ], $statusCode);
    }

    private function getStatusCodeForException(\Exception $e): int
    {
        return match (get_class($e)) {
            SecurityHeaderException::class => 400,
            SecurityContentException::class => 400,
            SecurityQueryException::class => 400,
            SecurityContextException::class => 401,
            SecurityPolicyException::class => 403,
            default => 500
        };
    }

    private function getPublicMessage(\Exception $e): string
    {
        return app()->environment('production')
            ? 'Security violation detected'
            : $e->getMessage();
    }
}
