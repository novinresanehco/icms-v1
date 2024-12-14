<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\{SecurityManager, Encryption};
use App\Core\Services\{ValidationService, AuditService};

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $auditor;
    private Encryption $encryption;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $auditor,
        Encryption $encryption,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthentication($credentials),
            ['operation' => 'authenticate']
        );
    }

    public function validateSession(string $token): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeSessionValidation($token),
            ['operation' => 'validate_session']
        );
    }

    public function authorize(int $userId, string $permission): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthorization($userId, $permission),
            ['operation' => 'authorize']
        );
    }

    public function generateToken(int $userId, array $context = []): string 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeTokenGeneration($userId, $context),
            ['operation' => 'generate_token']
        );
    }

    protected function executeAuthentication(array $credentials): AuthResult 
    {
        $this->validator->validateCredentials($credentials);
        
        $user = $this->findUser($credentials['username']);
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
            $this->handleFailedAuthentication($credentials);
            return new AuthResult(false);
        }

        if ($this->isMultiFactorRequired($user)) {
            return $this->initiateMultiFactor($user);
        }

        $token = $this->generateToken($user->id);
        $this->createSession($user->id, $token);
        
        return new AuthResult(true, $user, $token);
    }

    protected function executeSessionValidation(string $token): AuthResult 
    {
        $session = $this->findSession($token);
        
        if (!$session) {
            $this->handleInvalidSession($token);
            return new AuthResult(false);
        }

        if ($this->isSessionExpired($session)) {
            $this->handleExpiredSession($session);
            return new AuthResult(false);
        }

        $user = $this->findUserById($session->user_id);
        if (!$user) {
            $this->handleInvalidUser($session);
            return new AuthResult(false);
        }

        $this->extendSession($session);
        return new AuthResult(true, $user, $token);
    }

    protected function executeAuthorization(int $userId, string $permission): bool 
    {
        $user = $this->findUserById($userId);
        
        if (!$user) {
            $this->handleInvalidUser(['user_id' => $userId]);
            return false;
        }

        $permissions = $this->getUserPermissions($user);
        
        if (!in_array($permission, $permissions)) {
            $this->handleUnauthorizedAccess($user, $permission);
            return false;
        }

        return true;
    }

    protected function executeTokenGeneration(int $userId, array $context): string 
    {
        $payload = [
            'user_id' => $userId,
            'timestamp' => time(),
            'context' => $context,
            'nonce' => $this->generateNonce()
        ];

        $token = $this->encryption->encrypt(json_encode($payload));
        $this->storeToken($userId, $token);
        
        return $token;
    }

    protected function findUser(string $username): ?User 
    {
        return Cache::remember(
            "user:username:{$username}",
            $this->config['cache_ttl'],
            fn() => DB::table('users')->where('username', $username)->first()
        );
    }

    protected function findUserById(int $id): ?User 
    {
        return Cache::remember(
            "user:id:{$id}",
            $this->config['cache_ttl'],
            fn() => DB::table('users')->find($id)
        );
    }

    protected function findSession(string $token): ?Session 
    {
        return DB::table('sessions')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    protected function verifyPassword(string $input, string $hash): bool 
    {
        return Hash::check($input, $hash);
    }

    protected function isMultiFactorRequired(User $user): bool 
    {
        return $user->mfa_enabled || 
               in_array($user->role, $this->config['mfa_required_roles']);
    }

    protected function initiateMultiFactor(User $user): AuthResult 
    {
        $code = $this->generateMFACode();
        $this->storeMFACode($user->id, $code);
        $this->sendMFACode($user, $code);
        
        return new AuthResult(false, null, null, [
            'requires_mfa' => true,
            'mfa_session' => $this->generateMFASession($user->id)
        ]);
    }

    protected function createSession(int $userId, string $token): void 
    {
        DB::table('sessions')->insert([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => now()->addMinutes($this->config['session_lifetime']),
            'created_at' => now()
        ]);
    }

    protected function extendSession(Session $session): void 
    {
        DB::table('sessions')
            ->where('id', $session->id)
            ->update([
                'expires_at' => now()->addMinutes($this->config['session_lifetime'])
            ]);
    }

    protected function isSessionExpired(Session $session): bool 
    {
        return $session->expires_at < now();
    }

    protected function getUserPermissions(User $user): array 
    {
        return Cache::remember(
            "user:permissions:{$user->id}",
            $this->config['cache_ttl'],
            fn() => $this->loadUserPermissions($user)
        );
    }

    protected function generateNonce(): string 
    {
        return bin2hex(random_bytes(16));
    }

    protected function storeToken(int $userId, string $token): void 
    {
        Cache::put(
            "user:token:{$userId}",
            $token,
            now()->addMinutes($this->config['token_lifetime'])
        );
    }

    protected function handleFailedAuthentication(array $credentials): void 
    {
        $this->auditor->logSecurityEvent(
            'failed_authentication',
            ['username' => $credentials['username']],
            5
        );
    }

    protected function handleInvalidSession(string $token): void 
    {
        $this->auditor->logSecurityEvent(
            'invalid_session',
            ['token' => substr($token, 0, 8) . '...'],
            4
        );
    }

    protected function handleExpiredSession(Session $session): void 
    {
        $this->auditor->logSecurityEvent(
            'expired_session',
            ['user_id' => $session->user_id],
            3
        );
    }

    protected function handleInvalidUser(array $context): void 
    {
        $this->auditor->logSecurityEvent(
            'invalid_user',
            $context,
            4
        );
    }

    protected function handleUnauthorizedAccess(User $user, string $permission): void 
    {
        $this->auditor->logSecurityEvent(
            'unauthorized_access',
            [
                'user_id' => $user->id,
                'permission' => $permission
            ],
            4
        );
    }
}
