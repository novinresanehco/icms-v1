<?php

namespace App\Core\Api;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Interfaces\{
    ApiGatewayInterface,
    SecurityManagerInterface,
    ValidationInterface
};

class ApiGateway implements ApiGatewayInterface
{
    private SecurityManagerInterface $security;
    private ValidationInterface $validator;
    private RateLimiter $limiter;
    private RequestProcessor $processor;
    private ResponseBuilder $response;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationInterface $validator,
        RateLimiter $limiter,
        RequestProcessor $processor,
        ResponseBuilder $response
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->limiter = $limiter;
        $this->processor = $processor;
        $this->response = $response;
    }

    public function processRequest(ApiRequest $request): ApiResponse
    {
        // Validate API token
        $this->security->validateApiToken($request->getToken());
        
        // Check rate limits
        $this->limiter->checkLimit($request);
        
        // Process request
        try {
            $result = $this->processApiRequest($request);
            return $this->response->success($result);
        } catch (\Exception $e) {
            return $this->handleFailure($e, $request);
        }
    }

    private function processApiRequest(ApiRequest $request): mixed
    {
        // Validate request
        $this->validator->validateRequest($request);
        
        // Process through security layer
        return $this->security->processSecureOperation(
            fn() => $this->processor->process($request)
        );
    }

    private function handleFailure(\Exception $e, ApiRequest $request): ApiResponse
    {
        Log::error('API request failed', [
            'request' => $request->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->response->error($e);
    }
}

class RateLimiter
{
    private Cache $cache;
    private array $limits;

    public function checkLimit(ApiRequest $request): void
    {
        $key = $this->getLimitKey($request);
        
        if ($this->isLimitExceeded($key)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        $this->incrementCounter($key);
    }

    private function isLimitExceeded(string $key): bool
    {
        $count = Cache::get($key, 0);
        $limit = $this->getLimit($key);
        
        return $count >= $limit;
    }

    private function incrementCounter(string $key): void
    {
        Cache::increment($key);
        Cache::expire($key, $this->getLimitWindow($key));
    }
}

class RequestProcessor
{
    private ValidationService $validator;
    private array $processors;

    public function process(ApiRequest $request): mixed
    {
        // Get appropriate processor
        $processor = $this->getProcessor($request->getType());
        
        // Validate request data
        $this->validator->validateData(
            $request->getData(),
            $processor->getRules()
        );

        // Process request
        return $processor->execute($request);
    }

    private function getProcessor(string $type): RequestProcessorInterface
    {
        if (!isset($this->processors[$type])) {
            throw new ProcessorException("No processor for type: $type");
        }

        return $this->processors[$type];
    }
}

class ResponseBuilder
{
    private array $metadata;
    private EncryptionService $encryption;

    public function success($data): ApiResponse
    {
        return new ApiResponse([
            'status' => 'success',
            'data' => $this->prepareData($data),
            'metadata' => $this->getMetadata()
        ]);
    }

    public function error(\Exception $e): ApiResponse
    {
        return new ApiResponse([
            'status' => 'error',
            'error' => [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ],
            'metadata' => $this->getMetadata()
        ]);
    }

    private function prepareData($data): array
    {
        // Sanitize data
        $data = $this->sanitizeData($data);
        
        // Encrypt sensitive data
        return $this->encryption->encryptSensitive($data);
    }

    private function getMetadata(): array
    {
        return array_merge($this->metadata, [
            'timestamp' => now(),
            'version' => config('api.version')
        ]);
    }
}

class ApiRequest
{
    private array $data;
    private string $token;
    private string $type;
    private array $metadata;

    public function getToken(): string
    {
        return $this->token;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
            'metadata' => $this->metadata
        ];
    }
}

class ApiResponse
{
    private array $data;
    private int $statusCode;
    private array $headers;

    public function __construct(array $data, int $statusCode = 200, array $headers = [])
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
