<?php

namespace App\Core\Auth\Contracts;

interface AuthenticationInterface
{
    public function authenticate(array $credentials): ?User;
    public function validateToken(string $token): bool;
    public function createToken(User $user): string;
    public function revokeToken(string $token): void;
    public function logout(): void;
}

interface AuthorizationInterface
{
    public function hasPermission(User $user, string $permission): bool;
    public function hasRole(User $user, string $role): bool;
    public function assignRole(User $user, string $role): void;
    public function removeRole(User $user, string $role): void;
}

namespace App\Core\Auth\Services;

use App\Core\Auth\Contracts\AuthenticationInterface;
use App\Core\Auth\Events\UserLoggedIn;
use App\Core\Auth\Events\UserLoggedOut;
use App\Core\Auth\Exceptions\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthenticationService implements AuthenticationInterface
{
    protected UserRepository $userRepository;
    protected TokenRepository $tokenRepository;

    public function __construct(
        UserRepository $userRepository,
        TokenRepository $tokenRepository
    ) {
        $this->userRepository = $userRepository;
        $this->tokenRepository = $tokenRepository;
    }

    public function authenticate(array $credentials): ?User
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->is_active) {
            throw new AuthenticationException('Account is inactive');
        }

        event(new UserLoggedIn($user));

        return $user;
    }

    public function validateToken(string $token): bool
    {
        $tokenModel = $this->tokenRepository->findValidToken($token);
        return $tokenModel !== null;
    }

    public function createToken(User $user): string
    {
        $token = Str::random(60);
        
        $this->tokenRepository->create([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(30)
        ]);

        return $token;
    }

    public function revokeToken(string $token): void
    {
        $this->tokenRepository->invalidateToken($token);
    }

    public function logout(): void
    {
        $user = auth()->user();
        $this->tokenRepository->revokeAllUserTokens($user->id);
        event(new UserLoggedOut($user));
    }
}

namespace App\Core\Auth\Services;

use App\Core\Auth\Contracts\AuthorizationInterface;
use App\Core\Auth\Events\RoleAssigned;
use App\Core\Auth\Events\RoleRemoved;
use Illuminate\Support\Facades\Cache;

class AuthorizationService implements AuthorizationInterface
{
    protected RoleRepository $roleRepository;
    protected PermissionRepository $permissionRepository;

    public function __construct(
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository
    ) {
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return Cache::tags(['permissions', "user-{$user->id}"])
            ->remember("user.{$user->id}.permission.{$permission}", 3600, function () use ($user, $permission) {
                return $user->permissions()->where('name', $permission)->exists() ||
                    $user->roles()->whereHas('permissions', function ($query) use ($permission) {
                        $query->where('name', $permission);
                    })->exists();
            });
    }

    public function hasRole(User $user, string $role): bool
    {
        return Cache::tags(['roles', "user-{$user->id}"])
            ->remember("user.{$user->id}.role.{$role}", 3600, function () use ($user, $role) {
                return $user->roles()->where('name', $role)->exists();
            });
    }

    public function assignRole(User $user, string $role): void
    {
        $roleModel = $this->roleRepository->findByName($role);
        
        if (!$roleModel) {
            throw new RoleNotFoundException("Role {$role} not found");
        }

        $user->roles()->attach($roleModel->id);
        $this->clearUserCache($user->id);
        event(new RoleAssigned($user, $roleModel));
    }

    public function removeRole(User $user, string $role): void
    {
        $roleModel = $this->roleRepository->findByName($role);
        
        if (!$roleModel) {
            throw new RoleNotFoundException("Role {$role} not found");
        }

        $user->roles()->detach($roleModel->id);
        $this->clearUserCache($user->id);
        event(new RoleRemoved($user, $roleModel));
    }

    protected function clearUserCache(int $userId): void
    {
        Cache::tags(["user-{$userId}", 'roles', 'permissions'])->flush();
    }
}

namespace App\Core\Auth\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'email_verified_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withTimestamps();
    }

    public function hasPermissionTo(string $permission): bool
    {
        return app(AuthorizationInterface::class)->hasPermission($this, $permission);
    }

    public function hasRole(string $role): bool
    {
        return app(AuthorizationInterface::class)->hasRole($this, $role);
    }
}

namespace App\Core\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description'
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }
}

namespace App\Core\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'description'
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }
}
