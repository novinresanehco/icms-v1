<?php

namespace App\Core\Api;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Validation\ValidationService;
use App\Core\Authentication\AuthenticationManager;
use App\Core\Exceptions\ApiSecurityException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApiSecurityGateway implements ApiSecurityInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private ValidationService $validator;
    private AuthenticationManager $auth;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        ValidationService $validator,
        AuthenticationManager $auth,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->auth = $auth;
        $this->config = $config;
    }

    public function processRequest(Request $request): Request
    {
        $monitoringId = $this->monitor->startOperation('api_request_processing');
        
        try {
            $this->validateRequest($request);
            $this->authenticateRequest($request);
            $this->authorizeRequest($request);
            $this->validateRateLimit($request);
            
            $processedRequest = $this->sanitizeRequest($request);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $processedRequest;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ApiSecurityException('Request processing failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function processResponse(Response $response): Response
    {
        $monitoringId = $this->monitor->startOperation('api_response_processing');
        
        try {
            $this->validateResponse($response);
            $this->sanitizeResponse($response);
            $this->addSecurityHeaders($response);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ApiSecurityException('Response processing failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateRequest(Request $request): void
    {
        // Validate HTTP method
        if (!in_array($request->method(), $this->config['allowed_methods'])) {
            throw new ApiSecurityException('HTTP method not allowed');
        }

        // Validate content type
        if (!$this->validateContentType($request)) {
            throw new ApiSecurityException('Invalid content type');
        }

        // Validate request size
        if ($request->server('CONTENT_LENGTH') > $this->config['max_request_size']) {
            throw new ApiSecurityException('Request size exceeds limit');
        }

        // Validate request structure
        if (!$this->validator->validateRequest($request)) {
            throw new ApiSecurityException('Invalid request structure');
        }
    }

    private function authenticateRequest(Request $request): void
    {
        $token = $request->bearerToken();
        
        if (!$token || !$this->auth->validateToken($token)) {
            throw new ApiSecurityException('Authentication failed');
        }

        if (!$this->validateTokenPermissions($token, $request)) {
            throw new ApiSecurityException('Invalid token permissions');
        }
    }

    private function authorizeRequest(Request $request): void
    {
        $user = $this->auth->getUser($request->bearerToken());
        
        if (!$this->security->authorizeApiAccess($user, $request->path())) {
            throw new ApiSecurityException('Authorization failed');
        }

        if (!$this->validateResourceAccess($user, $request)) {
            throw new ApiSecurityException('Resource access denied');
        }
    }

    private function validateRateLimit(Request $request): void
    {
        $key = $this->getRateLimitKey($request);
        $limit = $this->getRateLimit($request);
        
        if ($this->isRateLimitExceeded($key, $limit)) {
            throw new ApiSecurityException('Rate limit exceeded');
        }
    }

    private function sanitizeRequest(Request $request): Request
    {
        $sanitized = clone $request;
        
        // Sanitize input data
        $sanitized->merge(
            $this->validator->sanitizeData($request->all())
        );

        // Remove sensitive headers
        foreach ($this->config['sensitive_headers'] as $header) {
            $sanitized->headers->remove($header);
        }

        return $sanitized;
    }

    private function validateResponse(Response $response): void
    {
        // Validate response structure
        if (!$this->validator->validateResponse($response)) {
            throw new ApiSecurityException('Invalid response structure');
        }

        // Validate sensitive data exposure
        if ($this->containsSensitiveData($response)) {
            throw new ApiSecurityException('Response contains sensitive data');
        }

        // Validate security headers
        if (!$this->validateSecurityHeaders($response)) {
            throw new ApiSecurityException('Missing required security headers');
        }
    }

    private function sanitizeResponse(Response $response): void
    {
        // Remove internal data
        $content = $response->getContent();
        $sanitized = $this->removeSensitiveData($content);
        $response->setContent($sanitized);

        // Remove internal headers
        foreach ($this->config['internal_headers'] as $header) {
            $response->headers->remove($header);
        }
    }

    private function addSecurityHeaders(Response $response): void
    {
        foreach ($this->config['security_headers'] as $header => $value) {
            $response->headers->set($header, $value);
        }
    }

    private function validateContentType(Request $request): bool
    {
        $contentType = $request->header('Content-Type');
        return in_array($contentType, $this->config['allowed_content_types']);
    }

    private function validateTokenPermissions(string $token, Request $request): bool
    {
        $permissions = $this->auth->getTokenPermissions($token);
        $requiredPermissions = $this->getRequiredPermissions($request);
        
        return !array_diff($requiredPermissions, $permissions);
    }

    private function validateResourceAccess(User $user, Request $request): bool
    {
        $resource = $this->getRequestedResource($request);
        return $this->security->validateResourceAccess($user, $resource);
    }

    private function getRateLimitKey(Request $request): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $request->ip(),
            $request->path()
        );
    }

    private function getRateLimit(Request $request): int
    {
        $path = $request->path();
        return $this->config['rate_limits'][$path] ?? $this->config['default_rate_limit'];
    }

    private function isRateLimitExceeded(string $key, int $limit): bool
    {
        $attempts = cache()->increment($key);
        
        if ($attempts === 1) {
            cache()->expire($key, $this->config['rate_limit_window']);
        }
        
        return $attempts > $limit;
    }

    private function containsSensitiveData($response): bool
    {
        $content = json_decode($response->getContent(), true);
        return $this->security->detectSensitiveData($content);
    }

    private function validateSecurityHeaders(Response $response): bool
    {
        foreach ($this->config['required_headers'] as $header) {
            if (!$response->headers->has($header)) {
                return false;
            }
        }
        return true;
    }

    private function removeSensitiveData($content): string
    {
        $data = json_decode($content, true);
        $filtered = $this->security->filterSensitiveData($data);
        return json_encode($filtered);
    }

    private function getRequiredPermissions(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();
        
        return $this->config['endpoint_permissions'][$path][$method] ?? [];
    }

    private function getRequestedResource(Request $request): string
    {
        return $request->path();
    }
}
