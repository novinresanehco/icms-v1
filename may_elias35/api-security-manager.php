<?php

namespace App\Core\API;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\SystemMonitor;

class ApiSecurityManager implements ApiSecurityInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private SystemMonitor $monitor;
    private array $config;
    
    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function validateRequest(ApiRequest $request): ApiValidationResult
    {
        $monitoringId = $this->monitor->startOperation('api_validation');
        
        try {
            $this->validateApiKey($request);
            $this->validateRateLimit($request);
            $this->validateEndpoint($request);
            $this->validatePayload($request);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return new ApiValidationResult(true);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new ApiSecurityException('API validation failed', 0, $e);
        }
    }

    public function processApiOperation(
        ApiOperation $operation,
        SecurityContext $context
    ): OperationResult {
        return $this->security->executeCriticalOperation(
            new ApiSecurityOperation($operation),
            $context
        );
    }

    private function validateApiKey(ApiRequest $request): void
    {
        $key = $request->getApiKey();
        
        if (!$this->cache->exists($this->getApiKeyCacheKey($key))) {
            throw new InvalidApiKeyException();
        }
        
        if ($this->isApiKeyExpired($key)) {
            throw new ExpiredApiKeyException();
        }
    }

    private function validateRateLimit(ApiRequest $request): void
    {
        $key = $request->getApiKey();
        $endpoint = $request->getEndpoint();
        
        $limits = $this->getRateLimits($key, $endpoint);
        $usage = $this->getCurrentUsage($key, $endpoint);
        
        if ($usage >= $limits['requests']) {
            throw new RateLimitExceededException();
        }
        
        $this->incrementUsage($key, $endpoint);
    }

    private function validateEndpoint(ApiRequest $request): void
    {
        $endpoint = $request->getEndpoint();
        $method = $request->getMethod();
        
        if (!$this->isValidEndpoint($endpoint, $method)) {
            throw new InvalidEndpointException();
        }
        
        if (!$this->hasEndpointAccess($request->getApiKey(), $endpoint)) {
            throw new EndpointAccessDeniedException();
        }
    }

    private function validatePayload(ApiRequest $request): void
    {
        $payload = $request->getPayload();
        $endpoint = $request->getEndpoint();
        
        $rules = $this->getValidationRules($endpoint);
        
        if (!$this->validator->validate($payload, $rules)) {
            throw new PayloadValidationException();
        }
    }
}

class ApiRateLimiter implements RateLimiterInterface
{
    private CacheManager $cache;
    private SystemMonitor $monitor;
    private array $config;

    public function __construct(
        CacheManager $cache,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function checkLimit(string $key, string $endpoint): bool
    {
        $monitoringId = $this->monitor->startOperation('rate_limit_check');
        
        try {
            $limits = $this->getLimits($key, $endpoint);
            $usage = $this->getUsage($key, $endpoint);
            
            if ($usage >= $limits['max_requests']) {
                $this->monitor->recordMetric($monitoringId, 'rate_limit_exceeded', 1);
                return false;
            }
            
            $this->incrementUsage($key, $endpoint);
            $this->monitor->recordMetric($monitoringId, 'rate_limit_current', $usage + 1);
            
            return true;
            
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function getLimits(string $key, string $endpoint): array
    {
        $keyType = $this->getKeyType($key);
        
        return [
            'max_requests' => $this->config['limits'][$keyType][$endpoint] ?? 
                            $this->config['limits'][$keyType]['default'],
            'window' => $this->config['window'][$keyType] ?? 3600
        ];
    }

    private function getUsage(string $key, string $endpoint): int
    {
        $usageKey = $this->getUsageKey($key, $endpoint);
        return (int) $this->cache->get($usageKey) ?? 0;
    }

    private function incrementUsage(string $key, string $endpoint): void
    {
        $usageKey = $this->getUsageKey($key, $endpoint);
        $window = $this->getLimits($key, $endpoint)['window'];
        
        $this->cache->increment($usageKey, 1, $window);
    }

    private function getKeyType(string $key): string
    {
        return $this->cache->get('api_key_type:' . $key) ?? 'default';
    }

    private function getUsageKey(string $key, string $endpoint): string
    {
        return "api_usage:{$key}:{$endpoint}:" . floor(time() / 60);
    }
}

class ApiEndpointValidator implements EndpointValidatorInterface
{
    private ValidationService $validator;
    private array $endpointConfig;

    public function __construct(
        ValidationService $validator,
        array $endpointConfig
    ) {
        $this->validator = $validator;
        $this->endpointConfig = $endpointConfig;
    }

    public function validateEndpoint(
        string $endpoint,
        string $method,
        array $payload
    ): ValidationResult {
        if (!$this->isValidEndpoint($endpoint, $method)) {
            throw new InvalidEndpointException();
        }
        
        if (!$this->validatePayload($endpoint, $payload)) {
            throw new PayloadValidationException();
        }
        
        return new ValidationResult(true);
    }

    private function isValidEndpoint(string $endpoint, string $method): bool
    {
        return isset($this->endpointConfig[$endpoint]) &&
               in_array($method, $this->endpointConfig[$endpoint]['methods']);
    }

    private function validatePayload(string $endpoint, array $payload): bool
    {
        $rules = $this->endpointConfig[$endpoint]['validation'] ?? [];
        return $this->validator->validate($payload, $rules);
    }
}
