<?php

namespace App\Core\Api;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{ApiManagerInterface, RateLimiterInterface};
use App\Core\Exceptions\{ApiException, SecurityException};

class ApiManager implements ApiManagerInterface
{
    private SecurityManager $security;
    private RequestValidator $validator;
    private RateLimiterInterface $rateLimiter;
    private ResponseFormatter $formatter;
    private array $config;

    public function __construct(
        SecurityManager $security,
        RequestValidator $validator,
        RateLimiterInterface $rateLimiter,
        ResponseFormatter $formatter,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->rateLimiter = $rateLimiter;
        $this->formatter = $formatter;
        $this->config = $config;
    }

    public function handleRequest(Request $request): Response
    {
        $requestId = $this->generateRequestId();

        return $this->security->executeCriticalOperation(
            fn() => $this->processApiRequest($request, $requestId),
            ['action' => 'api_request', 'request_id' => $requestId]
        );
    }

    protected function processApiRequest(Request $request, string $requestId): Response
    {
        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);

            $startTime = microtime(true);
            $response = $this->executeRequest($request, $requestId);
            $this->logRequestMetrics($request, $requestId, microtime(true) - $startTime);

            return $this->formatResponse($response, $requestId);

        } catch (\Exception $e) {
            return $this->handleRequestFailure($e, $request, $requestId);
        }
    }

    protected function validateRequest(Request $request): void
    {
        if (!$this->validator->validateApiVersion($request->version)) {
            throw new ApiException('Invalid API version');
        }

        if (!$this->validator->validateEndpoint($request->endpoint)) {
            throw new ApiException('Invalid endpoint');
        }

        if (!$this->validator->validateRequestData($request->data)) {
            throw new ApiException('Invalid request data');
        }

        if (!$this->security->validateApiToken($request->token)) {
            throw new SecurityException('Invalid API token');
        }
    }

    protected function checkRateLimit(Request $request): void
    {
        $key = $this->getRateLimitKey($request);
        
        if (!$this->rateLimiter->attempt($key)) {
            throw new ApiException('Rate limit exceeded', 429);
        }
    }

    protected function executeRequest(Request $request, string $requestId): mixed
    {
        $handler = $this->getRequestHandler($request->endpoint);
        
        return $this->security->executeCriticalOperation(
            fn() => $handler->handle($request),
            ['request_id' => $requestId, 'endpoint' => $request->endpoint]
        );
    }

    protected function formatResponse($data, string $requestId): Response
    {
        return $this->formatter->format($data, [
            'request_id' => $requestId,
            'timestamp' => time(),
            'version' => $this->config['api_version']
        ]);
    }

    protected function handleRequestFailure(\Exception $e, Request $request, string $requestId): Response
    {
        $this->logRequestFailure($e, $request, $requestId);

        $statusCode = $this->getErrorStatusCode($e);
        $error = $this->formatError($e, $requestId);

        return $this->formatter->formatError($error, $statusCode);
    }

    protected function getRequestHandler(string $endpoint): RequestHandlerInterface
    {
        if (!isset($this->config['handlers'][$endpoint])) {
            throw new ApiException('Endpoint handler not found');
        }

        $handler = app($this->config['handlers'][$endpoint]);

        if (!$handler instanceof RequestHandlerInterface) {
            throw new ApiException('Invalid endpoint handler');
        }

        return $handler;
    }

    protected function getRateLimitKey(Request $request): string
    {
        return sprintf(
            'api:ratelimit:%s:%s',
            $request->token,
            $request->endpoint
        );
    }

    protected function getErrorStatusCode(\Exception $e): int
    {
        if ($e instanceof ApiException) {
            return $e->getCode() ?: 400;
        }

        if ($e instanceof SecurityException) {
            return 403;
        }

        return 500;
    }

    protected function formatError(\Exception $e, string $requestId): array
    {
        $error = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'request_id' => $requestId
        ];

        if ($this->config['debug']) {
            $error['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }

        return $error;
    }

    protected function logRequestMetrics(Request $request, string $requestId, float $duration): void
    {
        $metrics = [
            'request_id' => $requestId,
            'endpoint' => $request->endpoint,
            'method' => $request->method,
            'duration' => $duration,
            'timestamp' => time()
        ];

        Log::info('API request completed', $metrics);

        if ($duration > $this->config['slow_request_threshold']) {
            Log::warning('Slow API request detected', $metrics);
        }

        $this->updateRequestStats($request->endpoint, $duration);
    }

    protected function logRequestFailure(\Exception $e, Request $request, string $requestId): void
    {
        Log::error('API request failed', [
            'request_id' => $requestId,
            'endpoint' => $request->endpoint,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->logSecurityEvent('api_security_failure', [
                'request_id' => $requestId,
                'endpoint' => $request->endpoint,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function updateRequestStats(string $endpoint, float $duration): void
    {
        $key = "api:stats:{$endpoint}";
        
        Cache::tags(['api', 'stats'])->remember($key, 3600, function() {
            return [
                'count' => 0,
                'total_time' => 0,
                'max_time' => 0
            ];
        });

        Cache::tags(['api', 'stats'])->increment("{$key}:count");
        Cache::tags(['api', 'stats'])->increment("{$key}:total_time", $duration);
        
        $maxTime = Cache::tags(['api', 'stats'])->get("{$key}:max_time", 0);
        if ($duration > $maxTime) {
            Cache::tags(['api', 'stats'])->put("{$key}:max_time", $duration);
        }
    }

    protected function generateRequestId(): string
    {
        return uniqid('req_', true);
    }
}
