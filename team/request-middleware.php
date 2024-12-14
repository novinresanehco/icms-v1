```php
<?php
namespace App\Core\Http;

class RequestProcessor implements RequestProcessorInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $logger;
    private array $middleware;

    public function process(Request $request): Response 
    {
        $requestId = $this->security->generateRequestId();
        
        try {
            $this->logger->logRequest($requestId, $request);
            $this->validateRequest($request);
            
            $request = $this->executeMiddleware($request);
            $response = $this->handleRequest($request);
            
            $this->logger->logResponse($requestId, $response);
            return $response;
            
        } catch (\Exception $e) {
            $this->handleRequestFailure($requestId, $e);
            throw new RequestException('Request processing failed', 0, $e);
        }
    }

    private function executeMiddleware(Request $request): Request 
    {
        foreach ($this->middleware as $middleware) {
            if (!$middleware->shouldProcess($request)) {
                continue;
            }
            
            try {
                $request = $middleware->process($request);
            } catch (\Exception $e) {
                throw new MiddlewareException(
                    "Middleware {$middleware->getName()} failed",
                    0,
                    $e
                );
            }
        }
        
        return $request;
    }

    private function validateRequest(Request $request): void 
    {
        if (!$this->validator->validateRequest($request)) {
            throw new ValidationException('Invalid request');
        }
    }
}

class SecurityMiddleware implements MiddlewareInterface 
{
    private SecurityManager $security;
    private TokenValidator $tokenValidator;
    private AuditLogger $logger;

    public function process(Request $request): Request 
    {
        $token = $request->getToken();
        
        if (!$this->tokenValidator->validate($token)) {
            $this->logger->logInvalidToken($token);
            throw new SecurityException('Invalid security token');
        }

        $user = $this->security->authenticateToken($token);
        return $request->withUser($user);
    }

    public function shouldProcess(Request $request): bool 
    {
        return !in_array($request->getPath(), $this->security->getPublicPaths());
    }
}

class ValidationMiddleware implements MiddlewareInterface 
{
    private ValidationService $validator;
    private array $rules;

    public function process(Request $request): Request 
    {
        $path = $request->getPath();
        
        if (isset($this->rules[$path])) {
            $validatedData = $this->validator->validate(
                $request->all(),
                $this->rules[$path]
            );
            return $request->withValidatedData($validatedData);
        }
        
        return $request;
    }

    public function shouldProcess(Request $request): bool 
    {
        return isset($this->rules[$request->getPath()]);
    }
}

class RateLimitMiddleware implements MiddlewareInterface 
{
    private RateLimiter $limiter;
    private AuditLogger $logger;

    public function process(Request $request): Request 
    {
        $key = $this->getLimitKey($request);
        
        if (!$this->limiter->attempt($key)) {
            $this->logger->logRateLimitExceeded($request);
            throw new RateLimitException('Rate limit exceeded');
        }
        
        return $request;
    }

    public function shouldProcess(Request $request): bool 
    {
        return !in_array($request->getPath(), $this->limiter->getUnlimitedPaths());
    }

    private function getLimitKey(Request $request): string 
    {
        return sprintf(
            '%s:%s:%s',
            $request->getIp(),
            $request->getPath(),
            date('Y-m-d-H')
        );
    }
}

interface RequestProcessorInterface 
{
    public function process(Request $request): Response;
}

interface MiddlewareInterface 
{
    public function process(Request $request): Request;
    public function shouldProcess(Request $request): bool;
}

class Request 
{
    private array $data;
    private array $headers;
    private ?User $user = null;
    private array $validatedData = [];

    public function withUser(User $user): self 
    {
        $clone = clone $this;
        $clone->user = $user;
        return $clone;
    }

    public function withValidatedData(array $data): self 
    {
        $clone = clone $this;
        $clone->validatedData = $data;
        return $clone;
    }
}
```
