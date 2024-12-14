<?php

namespace App\Core\Security;

use App\Core\Encryption\EncryptionService;
use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        CacheManager $cache,
        array $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function validateOperation(string $operation, array $data): void
    {
        // Rate limiting check
        $this->checkRateLimit($operation);
        
        // Input validation
        $validated = $this->validator->validate($data, $this->getValidationRules($operation));
        
        // Security checks
        $this->performSecurityChecks($operation, $validated);
        
        // Record validation attempt
        $this->auditLogger->logValidation($operation, $validated);
    }

    public function validateAccess(Request $request): void
    {
        DB::beginTransaction();
        
        try {
            // Authenticate request
            $user = $this->authenticateRequest($request);
            
            // Check permissions
            $this->validatePermissions($user, $request->getResource());
            
            // Verify request integrity
            $this->verifyRequestIntegrity($request);
            
            // Log successful access
            $this->auditLogger->logAccess($user, $request);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $request);
            throw $e;
        }
    }

    private function authenticateRequest(Request $request): User
    {
        $token = $request->bearerToken();
        if (!$token) {
            throw new AuthenticationException('No authentication token provided');
        }

        $cached = $this->cache->get("auth:token:{$token}");
        if ($cached) {
            return $cached;
        }

        $user = $this->validateToken($token);
        if (!$user) {
            throw new AuthenticationException('Invalid authentication token');
        }

        $this->cache->put("auth:token:{$token}", $user, 3600);
        return $user;
    }

    private function validateToken(string $token): ?User
    {
        try {
            $payload = $this->encryption->decrypt($token);
            return User::find($payload['user_id']);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function validatePermissions(User $user, string $resource): void
    {
        $permission = "access.{$resource}";
        
        if (!$this->hasPermission($user, $permission)) {
            throw new AuthorizationException("Access denied to resource: {$resource}");
        }
    }

    private function hasPermission(User $user, string $permission): bool
    {
        $cacheKey = "permissions:{$user->id}:{$permission}";
        
        return $this->cache->remember($cacheKey, 3600, function() use ($user, $permission) {
            return $user->hasPermission($permission);
        });
    }

    private function verifyRequestIntegrity(Request $request): void
    {
        if (!$this->encryption->verifyHmac($request)) {
            throw new IntegrityException('Request integrity check failed');
        }
    }

    private function checkRateLimit(string $operation): void
    {
        $key = "ratelimit:{$operation}:" . request()->ip();
        
        $attempts = (int)$this->cache->get($key, 0);
        if ($attempts >= $this->config['rate_limit']) {
            throw new RateLimitException('Rate limit exceeded');
        }
        
        $this->cache->increment($key);
        $this->cache->expire($key, 60);
    }

    private function performSecurityChecks(string $operation, array $data): void
    {
        // IP whitelist check
        if (!$this->isIpWhitelisted(request()->ip())) {
            throw new SecurityException('IP not whitelisted');
        }

        // Suspicious pattern check
        if ($this->hasSuspiciousPatterns($data)) {
            throw new SecurityException('Suspicious patterns detected');
        }

        // Additional security checks based on operation
        $this->performOperationSpecificChecks($operation, $data);
    }

    private function handleSecurityFailure(\Exception $e, Request $request): void
    {
        // Log security failure
        $this->auditLogger->logSecurityFailure($e, [
            'ip' => $request->ip(),
            'uri' => $request->getUri(),
            'method' => $request->getMethod(),
            'user_agent' => $request->userAgent()
        ]);

        // Increment failure count
        $key = "failures:" . $request->ip();
        $failures = $this->cache->increment($key);
        
        // Block IP if too many failures
        if ($failures >= $this->config['max_failures']) {
            $this->blockIp($request->ip());
        }
    }

    private function blockIp(string $ip): void
    {
        $this->cache->put("blocked:{$ip}", true, 3600);
        $this->auditLogger->logIpBlocked($ip);
    }

    private function hasSuspiciousPatterns(array $data): bool
    {
        foreach ($this->config['suspicious_patterns'] as $pattern) {
            if ($this->matchesPattern($data, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function matchesPattern(array $data, string $pattern): bool
    {
        return (bool)preg_match($pattern, json_encode($data));
    }

    private function getValidationRules(string $operation): array
    {
        return $this->config['validation_rules'][$operation] ?? [];
    }

    private function isIpWhitelisted(string $ip): bool
    {
        $whitelisted = $this->cache->remember('ip:whitelist', 3600, function() {
            return DB::table('ip_whitelist')->pluck('ip')->all();
        });
        
        return in_array($ip, $whitelisted);
    }

    private function performOperationSpecificChecks(string $operation, array $data): void
    {
        if (isset($this->config['operation_checks'][$operation])) {
            $checks = $this->config['operation_checks'][$operation];
            foreach ($checks as $check => $params) {
                $this->$check($data, $params);
            }
        }
    }
}
