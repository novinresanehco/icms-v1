<?php

namespace App\Services;

use App\Models\User;
use App\Interfaces\SecurityServiceInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class SecurityService implements SecurityServiceInterface 
{
    private $auditLogger;
    private $encryptionService;
    private $configManager;
    
    public function __construct(
        AuditLoggerInterface $auditLogger,
        EncryptionServiceInterface $encryptionService,
        ConfigManagerInterface $configManager
    ) {
        $this->auditLogger = $auditLogger;
        $this->encryptionService = $encryptionService;
        $this->configManager = $configManager;
    }

    /**
     * Validate security operation with comprehensive audit trail
     *
     * @param string $operation Operation name
     * @param array $context Operation context
     * @param callable $action Operation to execute
     * @throws SecurityException
     */
    public function validateSecurityOperation(string $operation, array $context, callable $action): mixed
    {
        // Start transaction for atomic operations
        DB::beginTransaction();
        
        try {
            // Pre-operation security checks
            $this->validateSecurityContext($operation, $context);
            
            // Execute operation with monitoring
            $startTime = microtime(true);
            $result = $action();
            $executionTime = microtime(true) - $startTime;
            
            // Log successful operation
            $this->auditLogger->logSecurityEvent(
                $operation, 
                'success',
                array_merge($context, ['execution_time' => $executionTime])
            );
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->auditLogger->logSecurityEvent(
                $operation,
                'failure',
                array_merge($context, [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ])
            );
            
            throw new SecurityException(
                "Security operation failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }
    
    /**
     * Validate user authentication with MFA support
     */
    public function validateAuthentication(User $user, array $credentials): bool
    {
        return $this->validateSecurityOperation(
            'authentication_validation',
            ['user_id' => $user->id],
            function() use ($user, $credentials) {
                // Validate password
                if (!Hash::check($credentials['password'], $user->password)) {
                    throw new AuthenticationException('Invalid credentials');
                }
                
                // Check MFA if enabled
                if ($this->configManager->isFeatureEnabled('mfa')) {
                    $this->validateMFA($user, $credentials);
                }
                
                // Update security metrics
                $this->updateSecurityMetrics('successful_auth', $user);
                
                return true;
            }
        );
    }
    
    /**
     * Validate authorization for protected operations
     */
    public function validateAuthorization(User $user, string $permission): bool 
    {
        return $this->validateSecurityOperation(
            'authorization_check',
            ['user_id' => $user->id, 'permission' => $permission],
            function() use ($user, $permission) {
                if (!$user->can($permission)) {
                    $this->auditLogger->logUnauthorizedAccess($user, $permission);
                    throw new AuthorizationException('Insufficient permissions');
                }
                
                return true;
            }
        );
    }

    /**
     * Encrypt sensitive data with audit trail
     */
    public function encryptSensitiveData(mixed $data, array $context = []): string
    {
        return $this->validateSecurityOperation(
            'data_encryption',
            $context,
            function() use ($data) {
                return $this->encryptionService->encrypt($data);
            }
        );
    }

    /**
     * Validate security context before operations
     */
    private function validateSecurityContext(string $operation, array $context): void
    {
        // Validate required context params
        if (!$this->hasRequiredContext($operation, $context)) {
            throw new SecurityException('Invalid security context');
        }

        // Check rate limiting
        if (!$this->checkRateLimit($operation, $context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // Verify security settings
        if (!$this->verifySecuritySettings($operation)) {
            throw new SecurityException('Security configuration invalid');
        }
    }

    /**
     * Update security metrics for monitoring
     */
    private function updateSecurityMetrics(string $metric, User $user): void
    {
        try {
            Cache::increment("security.metrics.{$metric}");
            
            // Store detailed metrics for analysis
            $this->storeMetricDetails($metric, [
                'user_id' => $user->id,
                'timestamp' => now(),
                'ip_address' => request()->ip()
            ]);
        } catch (\Exception $e) {
            // Log metric error but don't block operation
            Log::error("Failed to update security metrics: {$e->getMessage()}");
        }
    }

    // Additional protected methods for internal security operations...
}
