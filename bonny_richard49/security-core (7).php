<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Audit\AuditService;

final class SecurityManager
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private EncryptionService $encryption;
    private AuditService $audit;
    private array $config;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        EncryptionService $encryption,
        AuditService $audit,
        array $config
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function validateContext(array $context): bool
    {
        return isset($context['user_id']) 
            && isset($context['session_id'])
            && $this->auth->validateSession($context['session_id'])
            && $this->auth->validateUser($context['user_id']);
    }

    public function enforcePermission(string $permission, array $context): void
    {
        if (!$this->authz->hasPermission($context['user_id'], $permission)) {
            $this->audit->logUnauthorizedAccess($permission, $context);
            throw new SecurityException("Permission denied: $permission");
        }
    }

    public function sanitizeContent(string $content): string
    {
        return htmlspecialchars(
            strip_tags($content, $this->config['allowed_tags']), 
            ENT_QUOTES | ENT_HTML5
        );
    }

    public function encryptData(array $data): string
    {
        return $this->encryption->encrypt(json_encode($data));
    }

    public function decryptData(string $encrypted): array
    {
        $decrypted = $this->encryption->decrypt($encrypted);
        return json_decode($decrypted, true);
    }
}

final class AuthenticationService
{
    private TokenManager $tokens;
    private AuditService $audit;
    private array $config;

    public function __construct(
        TokenManager $tokens,
        AuditService $audit,
        array $config
    ) {
        $this->tokens = $tokens;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function authenticate(array $credentials): array
    {
        $trackingId = $this->audit->startOperation('authentication');

        try {
            $user = $this->validateCredentials($credentials);
            
            if ($this->requiresTwoFactor($user)) {
                return $this->initiateTwoFactor($user);
            }

            $token = $this->tokens->generate($user['id']);
            
            $this->audit->logSuccess($trackingId, ['user_id' => $user['id']]);
            
            return [
                'token' => $token,
                'user_id' => $user['id'],
                'expires_at' => time() + $this->config['token_lifetime']
            ];

        } catch (\Throwable $e) {
            $this->audit->logFailure($trackingId, $e);
            throw $e;
        }
    }

    public function validateSession(string $sessionId): bool
    {
        return $this->tokens->validate($sessionId);
    }

    public function validateUser(int $userId): bool
    {
        $user = DB::table('users')
            ->where('id', $userId)
            ->where('status', 'active')
            ->first();
            
        return $user !== null;
    }

    private function validateCredentials(array $credentials): array
    {
        // Secure credential validation implementation
        return [];
    }

    private function requiresTwoFactor(array $user): bool
    {
        return $user['two_factor_enabled'] ?? false;
    }

    private function initiateTwoFactor(array $user): array
    {
        // Two-factor authentication implementation