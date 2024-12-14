// File: app/Core/Auth/Manager/AuthManager.php
<?php

namespace App\Core\Auth\Manager;

class AuthManager
{
    protected UserRepository $userRepository;
    protected TokenManager $tokenManager;
    protected PasswordHasher $hasher;
    protected AuthConfig $config;
    protected EventDispatcher $events;

    public function authenticate(array $credentials): AuthResult
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user || !$this->hasher->verify($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new AuthenticationException('Account is not active');
        }

        $token = $this->tokenManager->createToken($user);
        $this->events->dispatch(new UserAuthenticated($user));

        return new AuthResult([
            'user' => $user,
            'token' => $token,
            'expires_at' => $token->expires_at
        ]);
    }

    public function validateToken(string $token): ?User
    {
        $tokenEntity = $this->tokenManager->validate($token);
        
        if (!$tokenEntity) {
            return null;
        }

        $user = $this->userRepository->find($tokenEntity->user_id);
        
        if (!$user || !$user->isActive()) {
            return null;
        }

        return $user;
    }

    public function logout(string $token): void
    {
        $this->tokenManager->invalidate($token);
        $this->events->dispatch(new UserLoggedOut($token));
    }
}

// File: app/Core/Auth/Token/TokenManager.php
<?php

namespace App\Core\Auth\Token;

class TokenManager
{
    protected TokenRepository $repository;
    protected TokenGenerator $generator;
    protected TokenEncrypter $encrypter;
    protected TokenConfig $config;

    public function createToken(User $user): Token
    {
        $token = $this->generator->generate();
        $expiresAt = now()->addMinutes($this->config->getTokenLifetime());

        $tokenEntity = $this->repository->create([
            'user_id' => $user->id,
            'token' => $this->encrypter->encrypt($token),
            'type' => 'access',
            'expires_at' => $expiresAt,
            'last_used_at' => now()
        ]);

        return new Token($tokenEntity);
    }

    public function validate(string $token): ?TokenEntity
    {
        $tokenEntity = $this->repository->findByToken(
            $this->encrypter->encrypt($token)
        );

        if (!$tokenEntity || $tokenEntity->isExpired()) {
            return null;
        }

        $tokenEntity->updateLastUsed();
        return $tokenEntity;
    }

    public function invalidate(string $token): void
    {
        $tokenEntity = $this->repository->findByToken(
            $this->encrypter->encrypt($token)
        );

        if ($tokenEntity) {
            $this->repository->delete($tokenEntity->id);
        }
    }
}

// File: app/Core/Auth/Password/PasswordHasher.php
<?php

namespace App\Core\Auth\Password;

class PasswordHasher
{
    protected HashConfig $config;

    public function hash(string $password): string
    {
        return password_hash($password, $this->config->getHashAlgorithm(), [
            'cost' => $this->config->getHashCost()
        ]);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash(
            $hash, 
            $this->config->getHashAlgorithm(),
            ['cost' => $this->config->getHashCost()]
        );
    }
}

// File: app/Core/Auth/Policy/PolicyManager.php
<?php

namespace App\Core\Auth\Policy;

class PolicyManager
{
    protected array $policies = [];
    protected RoleRepository $roleRepository;
    protected PermissionRepository $permissionRepository;

    public function registerPolicy(string $resource, Policy $policy): void
    {
        $this->policies[$resource] = $policy;
    }

    public function authorize(User $user, string $resource, string $action): bool
    {
        $policy = $this->getPolicy($resource);
        
        if (!$policy) {
            throw new PolicyException("No policy registered for resource: {$resource}");
        }

        if (method_exists($policy, 'before')) {
            $result = $policy->before($user, $action);
            if (!is_null($result)) {
                return $result;
            }
        }

        if (!method_exists($policy, $action)) {
            throw new PolicyException("Action {$action} not found in policy");
        }

        return $policy->$action($user);
    }

    protected function getPolicy(string $resource): ?Policy
    {
        return $this->policies[$resource] ?? null;
    }
}
