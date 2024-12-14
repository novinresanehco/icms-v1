<?php

namespace App\Core\Api;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{ApiException, SecurityException};

class ApiManager implements ApiManagerInterface
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private CacheManager $cache;
    private ValidationService $validator;
    private RateLimiter $limiter;
    private MetricsCollector $metrics;

    public function handleRequest(ApiRequest $request, SecurityContext $context): ApiResponse
    {
        return $this->protection->executeProtectedOperation(
            function() use ($request, $context) {
                $validatedRequest = $this->validateRequest($request);
                $this->enforceRateLimits($validatedRequest, $context);
                
                $response = $this->processRequest($validatedRequest, $context);
                return $this->prepareResponse($response);
            },
            $context
        );
    }

    public function registerEndpoint(string $path, EndpointConfig $config, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($path, $config, $context) {
                $validatedConfig = $this->validateEndpointConfig($config);
                $this->registerSecureEndpoint($path, $validatedConfig);
            },
            $context
        );
    }

    public function executeIntegration(IntegrationRequest $request, SecurityContext $context): IntegrationResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($request, $context) {
                $validated = $this->validateIntegrationRequest($request);
                return $this->processIntegration($validated, $context);
            },
            $context
        );
    }

    private function validateRequest(ApiRequest $request): ApiRequest
    {
        if (!$this->validator->validateRequest($request)) {
            throw new ApiException('Invalid API request');
        }

        if (!$this->security->validateApiSignature($request)) {
            throw new SecurityException('Invalid request signature');
        }

        return $request;
    }

    private function enforceRateLimits(ApiRequest $request, SecurityContext $context): void
    {
        $key = $this->generateRateLimitKey($request, $context);
        
        if (!$this->limiter->check($key)) {
            $this->metrics->incrementRateLimitExceeded($key);
            throw new ApiException('Rate limit exceeded');
        }
    }

    private function processRequest(ApiRequest $request, SecurityContext $context): mixed
    {
        $this->metrics->startRequest($request);
        
        try {
            $endpoint = $this->resolveEndpoint($request->getPath());
            $this->validateEndpointAccess($endpoint, $context);
            
            return $this->executeEndpoint($endpoint, $request);
            
        } finally {
            $this->metrics->endRequest($request);
        }
    }

    private function prepareResponse($data): ApiResponse
    {
        $response = new ApiResponse($data);
        
        $this->security->signResponse($response);
        $this->cacheResponse($response);
        
        return $response;
    }

    private function validateEndpointConfig(EndpointConfig $config): EndpointConfig
    {
        if (!$this->validator->validateEndpointConfig($config)) {
            throw new ApiException('Invalid endpoint configuration');
        }

        $this->validateSecurityRequirements($config->getSecurityConfig());
        return $config;
    }

    private function registerSecureEndpoint(string $path, EndpointConfig $config): void
    {
        $endpoint = new SecureEndpoint($path, $config);
        
        $this->validateEndpointSecurity($endpoint);
        $this->registerEndpointRoutes($endpoint);
        $this->setupEndpointMiddleware($endpoint);
    }

    private function validateIntegrationRequest(IntegrationRequest $request): IntegrationRequest
    {
        if (!$this->validator->validateIntegration($request)) {
            throw new ApiException('Invalid integration request');
        }

        $this->validateIntegrationSecurity($request);
        return $request;
    }

    private function processIntegration(IntegrationRequest $request, SecurityContext $context): IntegrationResult
    {
        $this->metrics->startIntegration($request);
        
        try {
            $integration = $this->resolveIntegration($request->getType());
            $result = $integration->execute($request->getData());
            
            $this->validateIntegrationResult($result);
            return $result;
            
        } finally {
            $this->metrics->endIntegration($request);
        }
    }

    private function validateEndpointSecurity(SecureEndpoint $endpoint): void
    {
        $securityScan = $this->security->scanEndpoint($endpoint);
        
        if ($securityScan->hasVulnerabilities()) {
            throw new SecurityException('Endpoint security scan failed');
        }
    }

    private function validateSecurityRequirements(SecurityConfig $config): void
    {
        if (!$config->meetsMinimumRequirements()) {
            throw new SecurityException('Insufficient security configuration');
        }
    }

    private function cacheResponse(ApiResponse $response): void
    {
        if ($response->isCacheable()) {
            $this->cache->store(
                $this->generateCacheKey($response),
                $response,
                $response->getCacheDuration()
            );
        }
    }

    private function validateIntegrationSecurity(IntegrationRequest $request): void
    {
        $securityCheck = $this->security->validateIntegration($request);
        
        if (!$securityCheck->isPassed()) {
            throw new SecurityException('Integration security check failed');
        }
    }

    private function validateIntegrationResult(IntegrationResult $result): void
    {
        if (!$this->validator->validateIntegrationResult($result)) {
            throw new ApiException('Invalid integration result');
        }
    }
}
