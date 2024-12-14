<?php

namespace App\Services;

use App\Models\{User, Role, Permission};
use App\Interfaces\SecurityServiceInterface;
use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Exceptions\{AuthenticationException, AuthorizationException};

class UserAuthService
{
    private SecurityServiceInterface $security;
    private AuditService $audit;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minutes

    public function __construct(
        SecurityServiceInterface $security,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->audit = $audit;
    }

    public function authenticate(array $credentials): User
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeAuthentication($credentials),
            ['action' => 'auth.login']
        );
    }

    private function executeAuthentication(array $credentials): User
    {
        $this->checkLoginAttempts($credentials['email']);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedLogin($credentials['email']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new AuthenticationException('Account is not active');
        }

        $this->clearLoginAttempts($credentials['email']);
        $this->audit->logSuccessfulLogin($user);

        return $user;
    }

    public function authorizeAction(User $user, string $permission): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->checkPermission($user, $permission),
            ['action' => 'auth.authorize', 'user_id' => $user->id]
        );
    }

    private function checkPermission(User $user, string $permission): bool
    {
        return Cache::remember(
            "user.{$user->id}.permission.{$permission}",
            3600,
            function() use ($user, $permission) {
                return $user->roles()
                    ->whereHas('permissions', function($query) use ($permission) {
                        $query->where('name', $permission);
                    })->exists();
            }
        );
    }

    public function assignRole(User $user, Role $role): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeRoleAssignment($user, $role),
            ['action' => 'user.role.assign', 'permission' => 'user.manage']
        );
    }

    private function executeRoleAssignment(User $user, Role $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
            
            // Clear permission cache
            $this->clearUserPermissionCache($user);
            
            $this->audit->logRoleAssignment($user, $role);
        });
    }

    public function createUser(array $userData): User
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeUserCreation($userData),
            ['action' => 'user.create', 'permission' => 'user.manage']
        );
    }

    private function executeUserCreation(array $userData): User
    {
        $this->validateUserData($userData);

        return DB::transaction(function() use ($userData) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'status' => 'active'
            ]);

            if (isset($userData['roles'])) {
                $user->roles()->attach($userData['roles']);
            }

            $this->audit->logUserCreation($user);

            return $user;
        });
    }

    public function updateUser(User $user, array $userData): User
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeUserUpdate($user, $userData),
            ['action' => 'user.update', 'permission' => 'user.manage']
        );
    }

    private function executeUserUpdate(User $user, array $userData): User
    {
        $this->validateUserData($userData, $user);

        return DB::transaction(function() use ($user, $userData) {
            if (isset($userData['password'])) {
                $userData['password'] = Hash::make($userData['password']);
            }

            $user->update($userData);

            if (isset($userData['roles'])) {
                $user->roles()->sync($userData['roles']);
                $this->clearUserPermissionCache($user);
            }

            $this->audit->logUserUpdate($user);

            return $user->fresh();
        });
    }

    private function checkLoginAttempts(string $email): void
    {
        $attempts = Cache::get("login.attempts.$email", 0);
        
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            throw new AuthenticationException(
                'Too many login attempts. Account locked for 15 minutes.'
            );
        }
    }

    private function handleFailedLogin(string $email): void
    {
        $attempts = Cache::get("login.attempts.$email", 0) + 1;
        Cache::put(
            "login.attempts.$email",
            $attempts,
            now()->addSeconds(self::LOCKOUT_TIME)
        );

        $this->audit->logFailedLogin($email);
    }

    private function clearLoginAttempts(string $email): void
    {
        Cache::forget("login.attempts.$email");
    }

    private function clearUserPermissionCache(User $user): void
    {
        Cache::tags(["user.{$user->id}.permissions"])->flush();
    }

    private function validateUserData(array $data, ?User $user = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                $user ? "unique:users,email,{$user->id}" : 'unique:users'
            ],
            'password' => $user ? 'sometimes|min:8' : 'required|min:8',
            'roles' => 'sometimes|array|exists:roles,id'
        ];

        $validator = validator($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }
}
