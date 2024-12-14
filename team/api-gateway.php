```php
<?php
namespace App\Core\Api;

class ApiGateway implements ApiGatewayInterface 
{
    private SecurityManager $security;
    private RateLimiter $rateLimiter;
    private RequestValidator $validator;
    private ResponseBuilder $responseBuilder;
    private MetricsCollector $metrics;

    public function processRequest(Request $request): Response 
    {
        $requestId = $this->security->generateRequestId();
        
        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);
            $this->authenticateRequest($request);
            
            $response = $this->handleRequest($request);
            
            $this->metrics->recordApiCall(
                $request->getEndpoint(),
                microtime(true) - $request->getStartTime()
            );
            
            return $response;
            
        } catch (ApiException $e) {
            $this->handleApiError($e, $requestId);
            return $this->responseBuilder->buildErrorResponse($e);
        }
    }

    private function validateRequest(Request $request): void 
    {
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid API request');
        }
    }

    private function checkRateLimit(Request $request): void 
    {
        $key = $this->getRateLimitKey($request);
        
        if (!$this->rateLimiter->attempt($key)) {
            throw new RateLimitException('API rate limit exceeded');
        }
    }

    private function authenticateRequest(Request $request): void 
    {
        if (!$this->security->authenticateApiRequest($request)) {
            throw new AuthenticationException('API authentication failed');
        }
    }

    private function getRateLimitKey(Request $request): string 
    {
        return sprintf(
            'api:%s:%s:%s',
            $request->getClientId(),
            $request->getEndpoint(),
            date('Y-m-d-H')
        );
    }
}

class ApiAuthenticator implements ApiAuthenticatorInterface 
{
    private SecurityManager $security;
    private KeyManager $keyManager;
    private AuditLogger $logger;

    public function authenticate(Request $request): bool 
    {
        try {
            $apiKey = $request->getApiKey();
            $signature = $request->getSignature();
            $timestamp = $request->getTimestamp();
            
            if (!$this->keyManager->isValidKey($apiKey)) {
                throw new AuthenticationException('Invalid API key');
            }
            
            if (!$this->isValidTimestamp($timestamp)) {
                throw new AuthenticationException('Invalid timestamp');
            }
            
            if (!$this->verifySignature($request, $signature)) {
                throw new AuthenticationException('Invalid signature');
            }
            
            $this->logger->logApiAuth($apiKey, true);
            return true;
            
        } catch (AuthenticationException $e) {
            $this->logger->logApiAuth($apiKey ?? 'unknown', false);
            throw $e;
        }
    }

    private function verifySignature(Request $request, string $signature): bool 
    {
        $expectedSignature = $this->security->generateRequestSignature(
            $request->getMethod(),
            $request->getEndpoint(),
            $request->getTimestamp(),
            $request->getBody()
        );
        
        return hash_equals($expectedSignature, $signature);
    }
}

class ApiRateLimiter implements RateLimiterInterface 
{
    private Cache $cache;
    private array $limits;
    private AuditLogger $logger;

    public function attempt(string $key): bool 
    {
        $current = (int)$this->cache->get($key, 0);
        $limit = $this->getLimit($key);
        
        if ($current >= $limit) {
            $this->logger->logRateLimit($key);
            return false;
        }
        
        $this->cache->increment($key);
        return true;
    }

    private function getLimit(string $key): int 
    {
        foreach ($this->limits as $pattern => $limit) {
            if (preg_match($pattern, $key)) {
                return $limit;
            }
        }
        
        return $this->limits['default'];
    }
}

interface ApiGatewayInterface 
{
    public function processRequest(Request $request): Response;
}

interface ApiAuthenticatorInterface 
{
    public function authenticate(Request $request): bool;
}

interface RateLimiterInterface 
{
    public function attempt(string $key): bool;
}
```
