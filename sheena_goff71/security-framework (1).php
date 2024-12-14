<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\Contracts\{
    SecurityManagerInterface,
    AuthenticationInterface,
    AuthorizationInterface,
    ValidationInterface,
    AuditInterface
};
use App\Core\Security\Services\{
    AccessControlService,
    EncryptionService,
    TokenService
};
use App\Core\Security\Models\{SecurityContext, SecurityOperation};
use App\Core\Security\Exceptions\{
    SecurityException,
    AuthenticationException,
    AuthorizationException,
    ValidationException
};

final class SecurityManager implements SecurityManagerInterface
{
    private AuthenticationInterface $auth;
    private AuthorizationInterface $authz;
    private ValidationInterface $validator;
    private AuditInterface $audit;
    private AccessControlService $access;
    private EncryptionService $encryption;
    private TokenService $token;
    
    private array $securityConfig;
    private array $validationRules;
    private array $securityMetrics;

    public function __construct(
        AuthenticationInterface $auth,
        AuthorizationInterface $authz,
        ValidationInterface $validator,
        AuditInterface $audit,
        AccessControlService $access,
        EncryptionService $encryption,
        TokenService $token,
        array $securityConfig
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->access = $access;
        $this->encryption = $encryption;
        $this->token = $token;
        $this->securityConfig = $securityConfig;
        $this->initializeSecurityFramework();
    }

    public function validateSecurityOperation(SecurityOperation $operation): void
    {
        DB::beginTransaction();
        $context = $this->createSecurityContext($operation);

        try {
            // 1. Validate Operation Context
            $this->validateContext($context);

            // 2. Authenticate & Authorize
            $this->authenticateRequest($context);
            $this->authorizeOperation($context);

            // 3. Validate Operation Data
            $this->validateOperationData($operation);

            // 4. Apply Security Controls
            $this->applySecurityControls($operation);

            // 5. Verify Resource Access
            $this->verifyResourceAccess($operation);

            // 6. Record Security Event
            $this->audit->logSecurityEvent($operation, $context);

            DB::commit();

        } catch (SecurityException $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw $e;
        }
    }

    public function enforceSecurityPolicy(string $policy, array $context = []): void
    {
        $policyConfig = $this->securityConfig['policies'][$policy] ?? null;
        if (!$policyConfig) {
            throw new SecurityException("Invalid security policy: {$policy}");
        }

        foreach ($policyConfig as $control => $settings) {
            $this->enforceSecurityControl($control, $settings, $context);
        }
    }

    public function validateDataIntegrity(array $data, string $context): bool
    {
        $hash = $this->encryption->generateHash($data);
        return $this->encryption->verifyHash($hash, $data);
    }

    public function encryptSensitiveData(array $data, array $options = []): array
    {
        foreach ($this->securityConfig['sensitive_fields'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->encryption->encrypt($data[$field], $options);
            }
        }
        return $data;
    }

    public function validateAccessToken(string $token): bool
    {
        return $this->token->validate($token, $this->securityConfig['token']);
    }

    public function generateSecureToken(array $claims): string
    {
        return $this->token->generate($claims, $this->securityConfig['token']);
    }

    public function revokeSecurityCredentials(string $identifier): void
    {
        $this->token->revoke($identifier);
        $this->access->revokeAccess($identifier);
        $this->audit->logCredentialRevocation($identifier);
    }

    public function getSecurityMetrics(): array
    {
        return $this->securityMetrics;
    }

    private function initializeSecurityFramework(): void
    {
        $this->validationRules = require config_path('security-rules.php');
        $this->securityMetrics = [
            'failed_attempts' => 0,
            'security_alerts' => 0,
            'active_sessions' => 0
        ];
    }

    private function createSecurityContext(SecurityOperation $operation): SecurityContext
    {
        return new SecurityContext([
            'operation' => $operation,
            'timestamp' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'session_id' => session()->getId()
        ]);
    }

    private function validateContext(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid security context');
        }
    }

    private function authenticateRequest(SecurityContext $context): void
    {
        if (!$this->auth->authenticate($context)) {
            $this->incrementFailedAttempts($context);
            throw new AuthenticationException('Authentication failed');
        }
    }

    private function authorizeOperation(SecurityContext $context): void
    {
        if (!$this->authz->authorize($context)) {
            throw new AuthorizationException('Operation not authorized');
        }
    }

    private function validateOperationData(SecurityOperation $operation): void
    {
        $rules = $this->validationRules[$operation->getType()] ?? [];
        if (!$this->validator->validate($operation->getData(), $rules)) {
            throw new ValidationException('Invalid operation data');
        }
    }

    private function applySecurityControls(SecurityOperation $operation): void
    {
        foreach ($this->securityConfig['controls'] as $control) {
            $this->enforceSecurityControl($control, [], ['operation' => $operation]);
        }
    }

    private function verifyResourceAccess(SecurityOperation $operation): void
    {
        if (!$this->access->verifyAccess($operation)) {
            throw new AuthorizationException('Resource access denied');
        }
    }

    private function handleSecurityFailure(SecurityException $e, SecurityContext $context): void
    {
        $this->securityMetrics['security_alerts']++;
        $this->audit->logSecurityFailure($e, $context);

        if ($e instanceof AuthenticationException) {
            $this->handleAuthenticationFailure($context);
        }

        Log::critical('Security violation detected', [
            'exception' => $e->getMessage(),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleAuthenticationFailure(SecurityContext $context): void
    {
        $this->incrementFailedAttempts($context);
        
        if ($this->detectBruteForceAttempt($context)) {
            $this->blockSuspiciousActivity($context);
        }
    }

    private function incrementFailedAttempts(SecurityContext $context): void
    {
        $key = "auth_failures:{$context->getIdentifier()}";
        Cache::increment($key, 1, $this->securityConfig['auth']['lockout_duration']);
        $this->securityMetrics['failed_attempts']++;
    }

    private function detectBruteForceAttempt(SecurityContext $context): bool
    {
        $attempts = Cache::get("auth_failures:{$context->getIdentifier()}", 0);
        return $attempts >= $this->securityConfig['auth']['max_attempts'];
    }

    private function blockSuspiciousActivity(SecurityContext $context): void
    {
        $this->access->blockAccess($context->getIdentifier());
        $this->audit->logSuspiciousActivity($context);
    }

    private function enforceSecurityControl(string $control, array $settings, array $context): void
    {
        $method = 'enforce' . studly_case($control);
        if (method_exists($this, $method)) {
            $this->$method($settings, $context);
        }
    }
}
