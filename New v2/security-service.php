<?php

namespace App\Core\Security;

class SecurityService implements SecurityInterface
{
    private AuthManager $auth;
    private AccessControl $access;
    private SecurityLogger $logger;
    private MonitoringService $monitor;
    private EncryptionService $encryption;

    public function __construct(
        AuthManager $auth,
        AccessControl $access,
        SecurityLogger $logger,
        MonitoringService $monitor,
        EncryptionService $encryption
    ) {
        $this->auth = $auth;
        $this->access = $access;
        $this->logger = $logger;
        $this->monitor = $monitor;
        $this->encryption = $encryption;
    }

    public function validateRequest(Request $request): SecurityResult
    {
        $context = new SecurityContext($request);
        
        try {
            DB::beginTransaction();

            // Authentication check
            $user = $this->auth->validateToken($request->bearerToken());
            $context->setUser($user);

            // Authorization check
            $this->access->validateAccess($user, $request->getResource());

            // Rate limiting
            $this->validateRateLimits($context);

            // Input validation
            $this->validateInput($request->all());

            DB::commit();
            return new SecurityResult(true);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw $e;
        }
    }

    public function encryptData(array $data): string
    {
        return $this->encryption->encrypt(
            json_encode($data),
            $this->getCurrentEncryptionKey()
        );
    }

    public function decryptData(string $encrypted): array
    {
        $json = $this->encryption->decrypt(
            $encrypted,
            $this->getCurrentEncryptionKey()
        );
        return json_decode($json, true);
    }

    protected function validateRateLimits(SecurityContext $context): void
    {
        $key = $this->getRateLimitKey($context);
        
        if ($this->isRateLimitExceeded($key)) {
            throw new RateLimitException();
        }

        $this->incrementRateLimit($key);
    }

    protected function validateInput(array $input): void
    {
        // Sanitize and validate all input
        foreach ($input as $key => $value) {
            if (!$this->isValidInput($key, $value)) {
                throw new InvalidInputException("Invalid input for field: {$key}");
            }
        }
    }

    protected function handleSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        $this->logger->logSecurityEvent('security_failure', [
            'error' => $e->getMessage(),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->trackSecurityFailure($e, $context);

        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e, $context);
        }
    }

    private function getCurrentEncryptionKey(): string
    {
        return Cache::remember('current_encryption_key', 3600, function() {
            return $this->encryption->generateKey();
        });
    }

    private function getRateLimitKey(SecurityContext $context): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $context->getUser()->id,
            $context->getResource()
        );
    }

    private function isRateLimitExceeded(string $key): bool
    {
        $attempts = Cache::get($key, 0);
        $maxAttempts = config('security.rate_limit.max_attempts', 60);
        
        return $attempts >= $maxAttempts;
    }

    private function incrementRateLimit(string $key): void
    {
        $ttl = config('security.rate_limit.window', 60);
        Cache::put($key, Cache::get($key, 0) + 1, $ttl);
    }

    private function isValidInput($key, $value): bool
    {
        return !preg_match('/[<>]/', $value) && 
               strlen($value) <= config('security.input.max_length', 1000);
    }
}

class SecurityContext
{
    private Request $request;
    private ?User $user = null;
    private array $metadata;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->metadata = [
            'ip' => $request->ip(),
            'timestamp' => microtime(true),
            'resource' => $request->getResource()
        ];
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResource(): string
    {
        return $this->metadata['resource'];
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user?->id,
            'metadata' => $this->metadata
        ];
    }
}

interface SecurityInterface
{
    public function validateRequest(Request $request): SecurityResult;
    public function encryptData(array $data): string;
    public function decryptData(string $encrypted): array;
}