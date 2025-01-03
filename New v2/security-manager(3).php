<?php

namespace App\Core\Security;

class SecurityManager implements SecurityInterface
{
    private TokenManager $tokens;
    private EncryptionService $encryption;
    private AccessControl $access;
    private AuditLogger $audit;
    private MetricsCollector $metrics;
    private SecurityConfig $config;

    public function __construct(
        TokenManager $tokens,
        EncryptionService $encryption,
        AccessControl $access,
        AuditLogger $audit,
        MetricsCollector $metrics,
        SecurityConfig $config
    ) {
        $this->tokens = $tokens;
        $this->encryption = $encryption;
        $this->access = $access;
        $this->audit = $audit;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function validateRequest(Request $request): SecurityValidation
    {
        DB::beginTransaction();
        
        try {
            $token = $this->validateToken($request);
            $context = $this->buildSecurityContext($request, $token);
            
            $this->validateAccess($context);
            $this->validateInputs($request);
            $this->validateRateLimits($context);
            
            DB::commit();
            
            $this->audit->logSuccess($context);
            return new SecurityValidation(true, $context);
            
        } catch (SecurityException $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $request);
            throw $e;
        }
    }

    public function encryptData(array $data): string
    {
        $key = $this->generateEncryptionKey();
        $encrypted = $this->encryption->encrypt(json_encode($data), $key);
        
        return $this->packEncryptedData($encrypted, $key);
    }

    public function decryptData(string $encrypted): array 
    {
        list($data, $key) = $this->unpackEncryptedData($encrypted);
        $decrypted = $this->encryption->decrypt($data, $key);
        
        return json_decode($decrypted, true);
    }

    public function generateAccessToken(User $user): AccessToken
    {
        $token = $this->tokens->generate([
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()
        ]);

        $this->audit->logTokenGeneration($user, $token);
        return $token;
    }

    public function validateOperation(Operation $operation, SecurityContext $context): void
    {
        if (!$this->access->validateOperation($operation, $context)) {
            throw new UnauthorizedOperationException();
        }

        $this->validateOperationInputs($operation);
        $this->enforceOperationLimits($operation, $context);
        $this->audit->logOperation($operation, $context);
    }

    protected function validateToken(Request $request): Token
    {
        $tokenString = $request->bearerToken();
        
        if (!$tokenString) {
            throw new AuthenticationException('Missing access token');
        }

        try {
            return $this->tokens->validate($tokenString);
        } catch (TokenException $e) {
            throw new AuthenticationException('Invalid token: ' . $e->getMessage());
        }
    }

    protected function buildSecurityContext(Request $request, Token $token): SecurityContext
    {
        return new SecurityContext(
            $token,
            $request->ip(),
            $request->userAgent(),
            $request->session()->getId(),
            $this->getRequestMetadata($request)
        );
    }

    protected function validateAccess(SecurityContext $context): void
    {
        if (!$this->access->validateAccess($context)) {
            throw new AccessDeniedException();
        }
    }

    protected function validateInputs(Request $request): void
    {
        foreach ($request->all() as $key => $value) {
            if (!$this->validateInput($key, $value)) {
                throw new SecurityInputException("Invalid input: {$key}");
            }
        }
    }

    protected function validateRateLimits(SecurityContext $context): void
    {
        if ($this->isRateLimited($context)) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    protected function handleSecurityFailure(SecurityException $e, Request $request): void
    {
        $this->audit->logFailure($e, [
            'request' => $this->getRequestMetadata($request),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('security.failure', [
            'type' => get_class($e),
            'code' => $e->getCode()
        ]);

        if ($e instanceof CriticalSecurityException) {
            $this->handleCriticalSecurityFailure($e, $request);
        }
    }

    protected function handleCriticalSecurityFailure(
        CriticalSecurityException $e, 
        Request $request
    ): void {
        $this->audit->logCritical($e, [
            'request' => $this->getRequestMetadata($request),
            'system_state' => $this->captureSystemState()
        ]);

        if ($this->config->get('block_on_critical_failure')) {
            $this->blockAccess($request);
        }

        $this->notifySecurityTeam($e, $request);
    }

    protected function generateEncryptionKey(): string
    {
        return $this->encryption->generateKey(
            $this->config->get('encryption_key_size', 256)
        );
    }

    protected function packEncryptedData(string $data, string $key): string
    {
        $packed = json_encode([
            'data' => base64_encode($data),
            'key' => base64_encode($key),
            'version' => 1,
            'algorithm' => 'AES-256-GCM'
        ]);

        return base64_encode($packed);
    }

    protected function unpackEncryptedData(string $packed): array
    {
        $data = json_decode(base64_decode($packed), true);
        
        if (!$data || !isset($data['data'], $data['key'])) {
            throw new SecurityException('Invalid encrypted data format');
        }

        return [
            base64_decode($data['data']),
            base64_decode($data['key'])
        ];
    }

    protected function validateInput($key, $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (!$this->validateInput($k, $v)) {
                    return false;
                }
            }
            return true;
        }

        return $this->validateInputValue($value);
    }

    protected function validateInputValue($value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        if (is_string($value)) {
            return $this->validateStringInput($value);
        }

        return true;
    }

    protected function validateStringInput(string $value): bool
    {
        // Implement string validation logic
        return true;
    }

    protected function validateOperationInputs(Operation $operation): void
    {
        $data = $operation->getData();
        
        foreach ($data as $key => $value) {
            if (!$this->validateOperationInput($key, $value)) {
                throw new SecurityInputException("Invalid operation input: {$key}");
            }
        }
    }

    protected function validateOperationInput($key, $value): bool
    {
        // Implement operation input validation
        return true;
    }

    protected function enforceOperationLimits(Operation $operation, SecurityContext $context): void
    {
        $limits = $this->config->get("operation_limits.{$operation->getType()}", []);
        
        foreach ($limits as $limit) {
            if (!$this->checkOperationLimit($operation, $context, $limit)) {
                throw new OperationLimitException("Operation limit exceeded: {$limit['type']}");
            }
        }
    }

    protected function checkOperationLimit(Operation $operation, SecurityContext $context, array $limit): bool
    {
        // Implement operation limit checking
        return true;
    }

    protected function isRateLimited(SecurityContext $context): bool
    {
        // Implement rate limiting logic
        return false;
    }

    protected function blockAccess(Request $request): void
    {
        // Implement access blocking logic
    }

    protected function notifySecurityTeam(SecurityException $e, Request $request): void
    {
        // Implement security team notification
    }

    protected function getRequestMetadata(Request $request): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => microtime(true)
        ];
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true),
            'active_connections' => $this->getActiveConnections()
        ];
    }

    protected function getActiveConnections(): int
    {
        // Implement active connections counting
        return 0;
    }
}
