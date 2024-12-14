<?php

namespace App\Core\API;

use Illuminate\Http\Request;
use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;

class ApiSecurityMiddleware
{
    private ApiSecurityManager $security;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        ApiSecurityManager $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function handle(Request $request, \Closure $next)
    {
        $monitoringId = $this->monitor->startOperation('api_request');
        
        try {
            $apiRequest = $this->createApiRequest($request);
            $context = $this->createSecurityContext($request);
            
            $this->security->validateRequest($apiRequest);
            
            $response = $next($request);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $this->wrapResponse($response);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            return $this->handleError($e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function createApiRequest(Request $request): ApiRequest
    {
        return new ApiRequest(
            $request->header('X-API-Key'),
            $request->path(),
            $request->method(),
            $request->all()
        );
    }

    private function createSecurityContext(Request $request): SecurityContext
    {
        return new SecurityContext(
            $request->header('X-API-Key'),
            $request->ip(),
            $request->userAgent()
        );
    }

    private function wrapResponse($response): Response
    {
        return response()->json([
            'success' => true,
            'data' => $response->getData()
        ], $response->getStatusCode());
    }

    private function handleError(\Exception $e): Response
    {
        $statusCode = $this->getErrorStatusCode($e);
        
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $e->getCode(),
                'message' => $this->getErrorMessage($e)
            ]
        ], $statusCode);
    }

    private function getErrorStatusCode(\Exception $e): int
    {
        return match (get_class($e)) {
            InvalidApiKeyException::class => 401,
            RateLimitExceededException::class => 429,
            PayloadValidationException::class => 400,
            EndpointAccessDeniedException::class => 403,
            default => 500
        };
    }

    private function getErrorMessage(\Exception $e): string
    {
        return $this->config['debug'] ? $e->getMessage() : 
            $this->config['error_messages'][get_class($e)] ?? 'An error occurred';
    }
}
