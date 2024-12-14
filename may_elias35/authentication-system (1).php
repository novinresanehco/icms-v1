<?php

namespace App\Core\Auth\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
        'metadata'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function hasRole($role): bool
    {
        return $this->roles->contains('name', $role);
    }

    public function hasPermission($permission): bool
    {
        return $this->permissions->contains('name', $permission) ||
               $this->roles->flatMap->permissions->contains('name', $permission);
    }
}

namespace App\Core\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'description'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}

namespace App\Core\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['name', 'description'];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}

namespace App\Core\Auth\Services;

use App\Core\Auth\Events\UserAuthenticated;
use App\Core\Auth\Exceptions\AuthenticationException;
use App\Core\Auth\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function authenticate(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        $user->update(['last_login_at' => now()]);

        event(new UserAuthenticated($user));

        return [
            'user' => $user,
            'token' => $token
        ];
    }

    public function logout(string $token): void
    {
        $user = auth()->user();
        $user->tokens()->where('token', hash('sha256', $token))->delete();
    }

    public function refreshToken(): string
    {
        $user = auth()->user();
        $user->tokens()->delete();
        return $user->createToken('auth-token')->plainTextToken;
    }
}

namespace App\Core\Auth\Services;

use App\Core\Auth\Exceptions\AuthorizationException;
use App\Core\Auth\Repositories\{RoleRepository, PermissionRepository};

class AuthorizationService
{
    private RoleRepository $roleRepository;
    private PermissionRepository $permissionRepository;

    public function __construct(
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository
    ) {
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
    }

    public function authorize(User $user, string $permission): bool
    {
        if (!$user->hasPermission($permission)) {
            throw new AuthorizationException("Unauthorized access to {$permission}");
        }

        return true;
    }

    public function assignRole(User $user, string $role): void
    {
        $roleModel = $this->roleRepository->findByName($role);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    public function revokeRole(User $user, string $role): void
    {
        $roleModel = $this->roleRepository->findByName($role);
        $user->roles()->detach($roleModel->id);
    }

    public function assignPermission(User $user, string $permission): void
    {
        $permissionModel = $this->permissionRepository->findByName($permission);
        $user->permissions()->syncWithoutDetaching([$permissionModel->id]);
    }

    public function revokePermission(User $user, string $permission): void
    {
        $permissionModel = $this->permissionRepository->findByName($permission);
        $user->permissions()->detach($permissionModel->id);
    }
}

namespace App\Core\Auth\Http\Controllers;

use App\Core\Auth\Services\AuthenticationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    private AuthenticationService $authService;

    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required'
            ]);

            $result = $this->authService->authenticate($request->only(['email', 'password']));

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->bearerToken());
            return response()->json(['message' => 'Logged out successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = $this->authService->refreshToken();
            return response()->json(['token' => $token]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Auth\Http\Controllers;

use App\Core\Auth\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    private AuthorizationService $authorizationService;

    public function __construct(AuthorizationService $authorizationService)
    {
        $this->authorizationService = $authorizationService;
    }

    public function assignRole(Request $request, int $userId): JsonResponse
    {
        try {
            $request->validate(['role' => 'required|string']);

            $user = User::findOrFail($userId);
            $this->authorizationService->assignRole($user, $request->input('role'));

            return response()->json(['message' => 'Role assigned successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function revokeRole(Request $request, int $userId): JsonResponse
    {
        try {
            $request->validate(['role' => 'required|string']);

            $user = User::findOrFail($userId);
            $this->authorizationService->revokeRole($user, $request->input('role'));

            return response()->json(['message' => 'Role revoked successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Auth\Http\Middleware;

use App\Core\Auth\Exceptions\AuthorizationException;
use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (!$request->user()->hasPermission($permission)) {
            throw new AuthorizationException("Unauthorized access to {$permission}");
        }

        return $next($request);
    }
}
