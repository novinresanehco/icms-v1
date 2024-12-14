<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\EncryptionService;

class CoreAuthManager
{
    private EncryptionService $encryption;
    private TokenManager $tokens;
    private SecurityLogger $logger;

    public function __construct(
        EncryptionService $encryption,
        TokenManager $tokens,
        SecurityLogger $logger
    ) {
        $this->encryption = $encryption;
        $this->tokens = $tokens;
        $this->logger = $logger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        try {
            $user = $this->validateUser($credentials);
            $token = $this->tokens->generate($user);
            
            $this->logger->logAuth($user->id);
            DB::commit();
            
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailedAuth($credentials['email']);
            throw $e;
        }
    }

    public function validateRequest(string $token): UserEntity
    {
        $payload = $this->tokens->validate($token);
        $user = Cache::remember(
            "user.{$payload->userId}",
            300,
            fn() => UserRepository::find($payload->userId)
        );
        
        if (!$user || !$user->isActive()) {
            throw new AuthException('Invalid user');
        }

        return $user;
    }

    protected function validateUser(array $credentials): UserEntity
    {
        $user = UserRepository::findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new AuthException('User inactive');
        }

        return $user;
    }
}

class TokenManager
{
    private string $key;
    private int $ttl;

    public function generate(UserEntity $user): string
    {
        $payload = [
            'userId' => $user->id,
            'role' => $user->role,
            'exp' => time() + $this->ttl
        ];

        return $this->encrypt($payload);
    }

    public function validate(string $token): object
    {
        try {
            $payload = $this->decrypt($token);
            
            if ($payload->exp < time()) {
                throw new TokenExpiredException();
            }

            return $payload;
        } catch (\Exception $e) {
            throw new InvalidTokenException();
        }
    }

    private function encrypt(array $payload): string
    {
        return openssl_encrypt(
            json_encode($payload),
            'AES-256-CBC',
            $this->key
        );
    }

    private function decrypt(string $token): object
    {
        $payload = openssl_decrypt(
            $token,
            'AES-256-CBC',
            $this->key
        );

        return json_decode($payload);
    }
}

class AccessControl
{
    private PermissionRepository $permissions;
    private SecurityLogger $logger;

    public function checkAccess(UserEntity $user, string $resource): bool
    {
        $permission = $this->permissions->find($user->role, $resource);
        
        if (!$permission) {
            $this->logger->logUnauthorizedAccess($user->id, $resource);
            return false;
        }

        return true;
    }

    public function validatePermission(UserEntity $user, string $permission): bool
    {
        return $this->permissions->userHasPermission($user->id, $permission);
    }
}

trait SecurityAware
{
    protected function validateSecurity(string $token): UserEntity
    {
        return app(CoreAuthManager::class)->validateRequest($token);
    }

    protected function checkPermission(UserEntity $user, string $permission): void
    {
        if (!app(AccessControl::class)->validatePermission($user, $permission)) {
            throw new UnauthorizedException();
        }
    }
}
