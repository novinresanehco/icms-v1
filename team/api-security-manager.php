<?php

namespace App\Core\API;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Security\DataProtection\DataProtectionService;
use App\Core\Database\SecureTransactionManager;
use App\Core\API\Events\APIEvent;
use App\Core\API\Exceptions\{APIException, SecurityException};

class APISecurityManager implements APISecurityInterface
{
    private SecurityManager $security;
    private DataProtectionService $protection;
    private SecureTransactionManager $transaction;
    private APIValidator $validator;
    private SecurityAudit $audit;
    private array $config;

    private const MAX_RATE_WINDOW = 3600;
    private const THROTTLE_DECAY = 60;

    public function __construct(
        SecurityManager $security,
        DataProtectionService $protection,
        SecureTransactionManager $transaction,
        APIValidator $validator,
        SecurityAudit $audit,
        array $config
    ) {
        $this->security = $security;
        $this->protection = $protection;
        $this->transaction = $transaction;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function validateAPIRequest(Request $request): APIValidationResult
    {
        try {
            $this->validateAuthentication($request);
            $this->validateRateLimit($request);
            
            $validatedData = $this->validateRequestData($request);
            $securityContext = $this->createSecurityContext($request);
            
            $this->validatePermissions($request, $securityContext);
            $this->audit->logAPIRequest($request, $securityContext);
            
            return new APIValidationResult(true, $validatedData, $securityContext);
            
        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $request);
            throw $e;
        }
    }

    public function processAPIResponse(Response $response, array $context): APIResponse
    {
        try {
            $this->validateResponseData($response);
            $processedData = $this->processResponseData($response, $context);
            
            $this->validateDataExposure($processedData, $context);
            $this->audit->logAPIResponse($response, $context);
            
            return new APIResponse(
                $this->protection->encryptResponse($processedData),
                $this->generateResponseMetadata($context)
            );
            
        } catch (\Exception $e) {
            $this->handleResponseFailure($e, $response, $context);
            throw $e;
        }
    }

    public function registerAPIEndpoint(string $path, array $config): void
    {
        return $this->transaction->executeSecureTransaction(function() use ($path, $config) {
            $this->validateEndpointConfig($config);
            $this->validateEndpointSecurity($path, $config);
            
            $endpoint = APIEndpoint::create([
                'path' => $path,
                'config' => $this->protection->encryptSensitiveData($config),
                'security_level' => $this->calculateSecurityLevel($config),
                'metadata' => $this->generateEndpointMetadata($config)
            ]);

            $this->registerEndpointRoutes($endpoint);
            $this->audit->logEndpointRegistration($endpoint);
            
        }, ['operation' => 'endpoint_registration']);
    }

    protected function validateAuthentication(Request $request): void
    {
        $token = $this->extractAuthToken($request);
        
        if (!$token || !$this->validateToken($token)) {
            throw new SecurityException('Invalid authentication token');
        }

        if ($this->isTokenRevoked($token)) {
            throw new SecurityException('Authentication token revoked');
        }

        if ($this->detectTokenAnomaly($token, $request)) {
            $this->audit->logTokenAnomaly($token, $request);
            throw new SecurityException('Token usage anomaly detected');
        }
    }

    protected function validateRateLimit(Request $request): void
    {
        $key = $this->generateRateLimitKey($request);
        $limit = $this->getRateLimit($request);
        
        if ($this->isRateLimitExceeded($key, $limit)) {
            $this->audit->logRateLimitExceeded($request);
            throw new APIException('Rate limit exceeded', 429);
        }

        $this->incrementRateLimit($key);
    }

    protected function validateRequestData(Request $request): array
    {
        $data = $request->all();
        
        if (!$this->validator->validateRequest($data, $request->route())) {
            throw new APIException('Invalid request data');
        }

        if ($this->detectMaliciousData($data)) {
            throw new SecurityException('Malicious request data detected');
        }

        return $data;
    }

    protected function createSecurityContext(Request $request): array
    {
        return [
            'client_id' => $this->extractClientId($request),
            'scope' => $this->extractScope($request),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String()
        ];
    }

    protected function validatePermissions(Request $request, array $context): void
    {
        if (!$this->security->hasPermission($request->route()->getName(), $context)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    protected function validateResponseData(Response $response): void
    {
        if (!$this->validator->validateResponse($response->getData())) {
            throw new APIException('Invalid response data');
        }
    }

    protected function processResponseData(Response $response, array $context): array
    {
        $data = $response->getData();
        
        return array_map(function ($item) use ($context) {
            return $this->processResponseItem($item, $context);
        }, $data);
    }

    protected function validateDataExposure(array $data, array $context): void
    {
        if ($this->detectSensitiveDataExposure($data, $context)) {
            throw new SecurityException('Unauthorized data exposure detected');
        }
    }

    private function extractAuthToken(Request $request): ?string
    {
        return $request->bearerToken() ?? $request->header('X-API-Token');
    }

    private function validateToken(string $token): bool
    {
        return $this->security->validateAPIToken($token);
    }

    private function isTokenRevoked(string $token): bool
    {
        return Cache::tags(['api_tokens'])->has("revoked_token:$token");
    }

    private function detectTokenAnomaly(string $token, Request $request): bool
    {
        return $this->security->detectAPIAnomaly($token, $request);
    }

    private function generateRateLimitKey(Request $request): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $this->extractClientId($request),
            $request->route()->getName()
        );
    }
}
