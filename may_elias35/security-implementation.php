<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Audit\AuditLogger;
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private AuditLogger $audit;
    private ValidationService $validator;
    private EncryptionService $encryption;

    public function executeSecureOperation(Operation $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            $this->validateRequest($operation, $context);
            $this->checkRateLimit($context);
            $this->validateMFA($context);
            
            $result = $this->executeWithProtection($operation, $context);
            
            DB::commit();
            $this->audit->logSuccess($operation, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $operation, $context);
            throw new SecurityException('Security violation', previous: $e);
        }
    }

    private function validateMFA(SecurityContext $context): void
    {
        $token = $this->auth->validateMFAToken($context->getMFAToken());
        if (!$token->isValid()) {
            $this->audit->logMFAFailure($context);
            throw new SecurityException('MFA validation failed');
        }
    }

    private function checkRateLimit(SecurityContext $context): void
    {
        $key = sprintf('rate_limit:%s:%s', $context->getUserId(), date('i'));
        $attempts = Cache::increment($key);
        
        if ($attempts > config('security.rate_limit')) {
            $this->audit->logRateLimit($context);
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(Operation $operation, SecurityContext $context): mixed
    {
        $permissions = $this->authz->getRequiredPermissions($operation);
        if (!$this->authz->checkPermissions($context->getUser(), $permissions)) {
            $this->audit->logUnauthorized($context, $permissions);
            throw new SecurityException('Insufficient permissions');
        }

        return $operation->execute();
    }

    private function handleSecurityFailure(\Throwable $e, Operation $operation, SecurityContext $context): void
    {
        $this->audit->logFailure($e, $operation, $context, [
            'trace' => $e->getTraceAsString(),
            'user_ip' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent()
        ]);

        if ($e instanceof SecurityException) {
            $this->audit->logSecurityEvent($e, $context);
            $this->handleSecurityEvent($e);
        }
    }
}

class AuthenticationService
{
    private TokenManager $tokens;
    private UserRepository $users;
    private HashingService $hasher;

    public function validateMFAToken(string $token): MFAToken
    {
        $decoded = $this->tokens->decode($token);
        if (!$decoded || $decoded->isExpired()) {
            throw new SecurityException('Invalid or expired MFA token');
        }

        if (!$this->tokens->verifySignature($decoded)) {
            throw new SecurityException('MFA token signature verification failed');
        }

        return $decoded;
    }

    public function authenticate(Credentials $credentials): AuthResult
    {
        $user = $this->users->findByUsername($credentials->username);
        if (!$user || !$this->hasher->verify($credentials->password, $user->password)) {
            throw new SecurityException('Invalid credentials');
        }

        if ($this->requiresMFA($user)) {
            return new MFAPendingResult($user);
        }

        return new SuccessfulAuthResult($user);
    }
}

class AuthorizationService
{
    private PermissionRegistry $permissions;
    private RoleHierarchy $roles;
    private AuditLogger $audit;

    public function checkPermissions(User $user, array $required): bool
    {
        $userPermissions = $this->roles->getEffectivePermissions($user->roles);
        
        foreach ($required as $permission) {
            if (!isset($userPermissions[$permission])) {
                return false;
            }
        }
        
        return true;
    }

    public function getRequiredPermissions(Operation $operation): array
    {
        return $this->permissions->getRequired($operation->getType());
    }
}

class EncryptionService
{
    private string $key;
    private string $cipher = 'aes-256-gcm';

    public function encrypt(string $data): EncryptedData
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return new EncryptedData($encrypted, $iv, $tag);
    }

    public function decrypt(EncryptedData $data): string
    {
        $decrypted = openssl_decrypt(
            $data->encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $data->iv,
            $data->tag
        );

        if ($decrypted === false) {
            throw new SecurityException('Decryption failed');
        }

        return $decrypted;
    }
}
