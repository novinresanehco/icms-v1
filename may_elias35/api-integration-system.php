<?php

namespace App\Core\Api;

class ApiGateway implements ApiInterface
{
    private SecurityManager $security;
    private RateLimiter $limiter;
    private RequestValidator $validator;
    private ResponseBuilder $response;
    private AuditLogger $logger;

    public function handleRequest(Request $request): Response
    {
        return $this->security->executeCriticalOperation(
            new HandleApiRequestOperation(
                $request,
                $this->limiter,
                $this->validator,
                $this->response
            )
        );
    }

    public function validateToken(string $token): bool
    {
        try {
            $payload = $this->security->verifyToken($token);
            return !$this->isTokenRevoked($payload);
        } catch (TokenException $e) {
            $this->logger->logFailedValidation($token, $e);
            return false;
        }
    }
}

class HandleApiRequestOperation implements CriticalOperation
{
    private Request $request;
    private RateLimiter $limiter;
    private RequestValidator $validator;
    private ResponseBuilder $response;

    public function execute(): Response
    {
        $this->checkRateLimit();
        $this->validateRequest();
        
        try {
            $result = $this->processRequest();
            return $this->response->success($result);
        } catch (ApiException $e) {
            return $this->handleApiError($e);
        }
    }

    private function checkRateLimit(): void
    {
        if (!$this->limiter->check($this->request)) {
            throw new RateLimitException(
                'Rate limit exceeded',
                429
            );
        }
    }

    private function validateRequest(): void
    {
        if (!$this->validator->validate($this->request)) {
            throw new ValidationException(
                $this->validator->getErrors()
            );
        }
    }
}

class RateLimiter
{
    private CacheManager $cache;
    private array $limits;

    public function check(Request $request): bool
    {
        $key = $this->getKey($request);
        $limit = $this->getLimit($request);

        return $this->cache->remember($key, function() use ($limit) {
            return $this->checkLimit($limit);
        }, 60);
    }

    private function checkLimit(array $limit): bool
    {
        $count = $this->cache->increment($limit['key']);
        return $count <= $limit['max'];
    }

    private function getKey(Request $request): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $request->ip(),
            $request->path()
        );
    }
}

class RequestValidator
{
    private ValidationService $validator;
    private SchemaRegistry $schemas;
    private array $errors = [];

    public function validate(Request $request): bool
    {
        $schema = $this->schemas->getForEndpoint(
            $request->path()
        );

        if (!$schema) {
            throw new ValidationException('No schema defined');
        }

        return $this->validator->validate(
            $request->all(),
            $schema->getRules()
        );
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class ApiIntegrationManager
{
    private ServiceRegistry $services;
    private SecurityManager $security;
    private CircuitBreaker $breaker;

    public function callExternalService(
        string $service,
        string $method,
        array $data
    ): ServiceResult {
        $serviceConfig = $this->services->get($service);
        
        if (!$serviceConfig) {
            throw new ServiceException('Service not registered');
        }

        return $this->breaker->execute(
            $service,
            fn() => $this->executeServiceCall(
                $serviceConfig,
                $method,
                $data
            )
        );
    }

    private function executeServiceCall(
        ServiceConfig $config,
        string $method,
        array $data
    ): ServiceResult {
        $client = $this->createSecureClient($config);
        
        try {
            $response = $client->request(
                $method,
                $config->getEndpoint(),
                $this->prepareRequest($data)
            );

            return new ServiceResult($response);
        } catch (ClientException $e) {
            throw new ServiceException(
                "Service call failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    private function createSecureClient(ServiceConfig $config): Client
    {
        return new Client([
            'base_uri' => $config->getBaseUrl(),
            'timeout' => $config->getTimeout(),
            'verify' => true,
            'cert' => $config->getCertificate(),
            'headers' => [
                'X-API-Key' => $this->security->getServiceKey($config->getName()),
                'User-Agent' => 'CMS-Integration/1.0'
            ]
        ]);
    }
}

class CircuitBreaker
{
    private CacheManager $cache;
    private array $config;
    private AuditLogger $logger;

    public function execute(string $service, callable $operation): mixed
    {
        $state = $this->getState($service);
        
        if ($state->isOpen()) {
            throw new CircuitOpenException($service);
        }

        try {
            $result = $operation();
            $this->recordSuccess($service);
            return $result;
        } catch (\Exception $e) {
            $this->recordFailure($service, $e);
            throw $e;
        }
    }

    private function getState(string $service): CircuitState
    {
        return $this->cache->remember(
            "circuit:$service",
            fn() => new CircuitState($service)
        );
    }

    private function recordFailure(string $service, \Exception $e): void
    {
        $state = $this->getState($service);
        $state->recordFailure();

        if ($state->shouldOpen()) {
            $state->open();
            $this->logger->logCircuitOpen($service, $e);
        }

        $this->cache->put("circuit:$service", $state);
    }
}
