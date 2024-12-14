<?php

namespace App\Core\Security;

class SecurityService
{
    private AuthenticationManager $authManager;
    private AuthorizationManager $authzManager;
    private TokenManager $tokenManager;
    private SecurityLogger $logger;

    public function __construct(
        AuthenticationManager $authManager,
        AuthorizationManager $authzManager,
        TokenManager $tokenManager,
        SecurityLogger $logger
    ) {
        $this->authManager = $authManager;
        $this->authzManager = $authzManager;
        $this->tokenManager = $tokenManager;
        $this->logger = $logger;
    }

    public function authenticate(array $credentials): AuthenticationResult
    {
        try {
            $user = $this->authManager->authenticate($credentials);
            $token = $this->tokenManager->generate($user);

            $this->logger->logAuthentication($user, true);

            return new AuthenticationResult($user, $token);
        } catch (AuthenticationException $e) {
            $this->logger->logAuthenticationFailure($credentials, $e);
            throw $e;
        }
    }

    public function authorize(User $user, string $permission, $resource = null): bool
    {
        try {
            $result = $this->authzManager->authorize($user, $permission, $resource);
            $this->logger->logAuthorization($user, $permission, $resource, $result);
            return $result;
        } catch (AuthorizationException $e) {
            $this->logger->logAuthorizationFailure($user, $permission, $resource, $e);
            throw $e;
        }
    }

    public function validateToken(string $token): ?User
    {
        try {
            $payload = $this->tokenManager->validate($token);
            return $this->authManager->getUserById($payload['user_id']);
        } catch (TokenException $e) {
            $this->logger->logTokenValidationFailure($token, $e);
            return null;
        }
    }

    public function revokeToken(string $token): void
    {
        $this->tokenManager->revoke($token);
        $this->logger->logTokenRevocation($token);
    }
}

class AuthenticationManager
{
    private UserRepository $userRepository;
    private PasswordHasher $passwordHasher;
    private array $providers;

    public function authenticate(array $credentials): User
    {
        foreach ($this->providers as $provider) {
            try {
                return $provider->authenticate($credentials);
            } catch (AuthenticationException $e) {
                continue;
            }
        }

        throw new AuthenticationException('Invalid credentials');
    }

    public function getUserById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }
}

class AuthorizationManager
{
    private PermissionRegistry $permissionRegistry;
    private RoleHierarchy $roleHierarchy;
    private PolicyManager $policyManager;

    public function authorize(User $user, string $permission, $resource = null): bool
    {
        if ($this->hasDirectPermission($user, $permission)) {
            return true;
        }

        if ($this->hasRolePermission($user, $permission)) {
            return true;
        }

        if ($resource && $this->satisfiesPolicy($user, $permission, $resource)) {
            return true;
        }

        return false;
    }

    protected function hasDirectPermission(User $user, string $permission): bool
    {
        return $this->permissionRegistry->userHasPermission($user, $permission);
    }

    protected function hasRolePermission(User $user, string $permission): bool
    {
        $roles = $this->roleHierarchy->getUserRoles($user);
        return $this->permissionRegistry->rolesHavePermission($roles, $permission);
    }

    protected function satisfiesPolicy(User $user, string $permission, $resource): bool
    {
        return $this->policyManager->evaluate($user, $permission, $resource);
    }
}

class TokenManager
{
    private string $secretKey;
    private TokenStorage $storage;
    private int $ttl;

    public function generate(User $user): string
    {
        $payload = [
            'user_id' => $user->getId(),
            'roles' => $user->getRoles(),
            'exp' => time() + $this->ttl
        ];

        $token = $this->createToken($payload);
        $this->storage->store($token, $payload);

        return $token;
    }

    public function validate(string $token): array
    {
        if (!$this->storage->exists($token)) {
            throw new TokenException('Token not found');
        }

        $payload = $this->storage->get($token);

        if ($payload['exp'] < time()) {
            $this->storage->remove($token);
            throw new TokenException('Token expired');
        }

        return $payload;
    }

    public function revoke(string $token): void
    {
        $this->storage->remove($token);
    }

    protected function createToken(array $payload): string
    {
        return hash_hmac('sha256', serialize($payload), $this->secretKey);
    }
}
