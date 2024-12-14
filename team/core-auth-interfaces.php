<?php

namespace App\Core\Auth;

use Illuminate\Support\Collection;
use App\Core\Security\SecurityContext;

interface UserRepositoryInterface {
    public function find(int $id): ?User;
    public function findByUsername(string $username): ?User;
    public function create(array $data): User;
    public function update(int $id, array $data): User;
    public function delete(int $id): bool;
}

interface SecurityAwareInterface {
    public function validateAccess(SecurityContext $context): bool;
    public function getRequiredPermissions(): array;
    public function getSecurityLevel(): string;
}

class UserRepository implements UserRepositoryInterface {
    private User $model;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        User $model, 
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function find(int $id): ?User 
    {
        return $this->cache->remember("user:{$id}", function() use ($id) {
            return $this->model->find($id);
        });
    }

    public function findByUsername(string $username): ?User 
    {
        return $this->cache->remember(
            "user:username:{$username}", 
            function() use ($username) {
                return $this->model->where('username', $username)
                    ->where('active', true)
                    ->first();
            }
        );
    }

    public function create(array $data): User 
    {
        DB::beginTransaction();
        
        try {
            // Validate user data
            $validated = $this->validator->validateUserData($data);
            
            // Create user with secure defaults
            $user = $this->model->create(array_merge($validated, [
                'active' => true,
                'failed_attempts' => 0,
                'last_login' => null
            ]));
            
            // Create initial security settings
            $this->setupUserSecurity($user);
            
            // Log user creation
            $this->auditLogger->logUserCreation($user);
            
            DB::commit();
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedUserCreation($data, $e);
            throw $e;
        }
    }

    public function update(int $id, array $data): User 
    {
        DB::beginTransaction();
        
        try {
            $user = $this->find($id);
            if (!$user) {
                throw new UserNotFoundException("User {$id} not found");
            }
            
            // Validate update data
            $validated = $this->validator->validateUserData($data, $user);
            
            // Update user
            $user->update($validated);
            
            // Clear user cache
            $this->clearUserCache($user);
            
            // Log update
            $this->auditLogger->logUserUpdate($user, $data);
            
            DB::commit();
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedUserUpdate($id, $data, $e);
            throw $e;
        }
    }

    public function delete(int $id): bool 
    {
        DB::beginTransaction();
        
        try {
            $user = $this->find($id);
            if (!$user) {
                throw new UserNotFoundException("User {$id} not found");
            }
            
            // Soft delete user
            $user->update(['active' => false]);
            
            // Clear user cache
            $this->clearUserCache($user);
            
            // Log deletion
            $this->auditLogger->logUserDeletion($user);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailedUserDeletion($id, $e);
            throw $e;
        }
    }

    private function setupUserSecurity(User $user): void 
    {
        // Set up default security settings
        SecuritySettings::create([
            'user_id' => $user->id,
            'mfa_enabled' => config('auth.default_mfa_enabled'),
            'password_expires_at' => now()->addDays(config('auth.password_expiry_days')),
            'security_level' => config('auth.default_security_level')
        ]);
    }

    private function clearUserCache(User $user): void 
    {
        $this->cache->forget("user:{$user->id}");
        $this->cache->forget("user:username:{$user->username}");
    }
}

class SecurityContext {
    private User $user;
    private string $action;
    private array $parameters;
    private string $ip;
    private string $userAgent;

    public function __construct(
        User $user, 
        string $action, 
        array $parameters = [],
        ?string $ip = null,
        ?string $userAgent = null
    ) {
        $this->user = $user;
        $this->action = $action;
        $this->parameters = $parameters;
        $this->ip = $ip ?? request()->ip();
        $this->userAgent = $userAgent ?? request()->userAgent();
    }

    public function getUser(): User 
    {
        return $this->user;
    }

    public function getAction(): string 
    {
        return $this->action;
    }

    public function getParameters(): array 
    {
        return $this->parameters;
    }

    public function getIp(): string 
    {
        return $this->ip;
    }

    public function getUserAgent(): string 
    {
        return $this->userAgent;
    }
}
