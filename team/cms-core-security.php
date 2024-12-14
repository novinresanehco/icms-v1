<?php

namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    private SecurityConfig $config;

    public function validateOperation(Operation $operation): void 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution security validation
            $this->validateSecurity($operation);
            
            // Input validation
            $this->validateInput($operation->getData());
            
            // Resource validation 
            $this->validateResources($operation);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $operation);
            throw $e;
        }
    }

    private function validateSecurity(Operation $operation): void
    {
        // Validate authentication
        if (!$this->validateAuth($operation->getContext())) {
            throw new AuthenticationException();
        }

        // Validate authorization
        if (!$this->validateAccess($operation->getContext())) {
            throw new AuthorizationException(); 
        }

        // Validate rate limits
        if (!$this->validateRateLimits($operation->getContext())) {
            throw new RateLimitException();
        }
    }

    private function validateInput(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->validator->validate($key, $value)) {
                throw new ValidationException("Invalid input: {$key}");
            }
        }
    }

    private function validateResources(Operation $operation): void
    {
        $required = $operation->getRequiredResources();
        $available = $this->getAvailableResources();

        foreach ($required as $resource => $amount) {
            if (!isset($available[$resource]) || $available[$resource] < $amount) {
                throw new ResourceException("Insufficient {$resource}");
            }
        }
    }

    private function handleSecurityFailure(\Exception $e, Operation $operation): void
    {
        // Log failure
        $this->audit->logSecurityFailure($e, $operation);

        // Increment failure count
        $this->incrementFailureCount($operation->getContext());

        // Check for attack patterns
        if ($this->detectAttackPattern($operation->getContext())) {
            $this->blockSuspiciousActivity($operation->getContext());
        }

        // Notify security team if threshold exceeded
        if ($this->config->shouldNotifyTeam($e)) {
            $this->notifySecurityTeam($e, $operation);
        }
    }

    private function incrementFailureCount(SecurityContext $context): void
    {
        $key = "security:failures:{$context->getIdentifier()}";
        Cache::increment($key);
        
        if (Cache::get($key) >= $this->config->getMaxFailures()) {
            $this->lockAccount($context);
        }
    }

    private function detectAttackPattern(SecurityContext $context): bool
    {
        $recentAttempts = $this->audit->getRecentAttempts($context);
        return $this->patternMatcher->detectSuspiciousPattern($recentAttempts);
    }

    private function blockSuspiciousActivity(SecurityContext $context): void
    {
        $this->audit->logBlockedActivity($context);
        $this->security->blockAccess($context, $this->config->getBlockDuration());
        $this->notifySecurityTeam("Suspicious activity blocked", $context);
    }

    private function validateAuth(SecurityContext $context): bool
    {
        return $this->encryption->verify(
            $context->getToken(),
            $context->getSignature(),
            $this->config->getSecurityKey()
        );
    }

    private function validateAccess(SecurityContext $context): bool
    {
        $required = $context->getRequiredPermissions();
        $actual = $this->access->getPermissions($context->getUser());
        
        return count(array_intersect($required, $actual)) === count($required);
    }

    private function validateRateLimits(SecurityContext $context): bool
    {
        $key = "rate:limit:{$context->getIdentifier()}";
        $attempts = Cache::increment($key);
        
        if ($attempts === 1) {
            Cache::expire($key, $this->config->getRateLimitWindow());
        }
        
        return $attempts <= $this->config->getRateLimit();
    }
}

interface SecurityManagerInterface
{
    public function validateOperation(Operation $operation): void;
}

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private CacheManager $cache;
    private ValidationService $validator;

    public function createContent(array $data, SecurityContext $context): Content
    {
        // Validate security context
        $this->security->validateOperation(new CreateOperation($data, $context));

        // Validate content data
        $validated = $this->validator->validateContent($data);

        DB::beginTransaction();
        try {
            // Create content
            $content = $this->database->create('content', $validated);
            
            // Cache content
            $this->cache->put(
                "content:{$content->id}",
                $content,
                $this->config->getCacheDuration()
            );

            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        // Validate security
        $this->security->validateOperation(new UpdateOperation($id, $data, $context));

        // Get existing content
        $content = $this->database->find('content', $id);
        if (!$content) {
            throw new NotFoundException();
        }

        // Validate updates
        $validated = $this->validator->validateContent($data);

        DB::beginTransaction();
        try {
            // Update content
            $updated = $this->database->update('content', $id, $validated);
            
            // Update cache
            $this->cache->put(
                "content:{$id}",
                $updated,
                $this->config->getCacheDuration()
            );

            DB::commit();
            return $updated;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
