<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Exceptions\AuthException;

class AuthenticationManager
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenManager $tokens;
    private RoleManager $roles;

    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        TokenManager $tokens,
        RoleManager $roles
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->tokens = $tokens;
        $this->roles = $roles;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->verifyCredentials($credentials),
            ['action' => 'authenticate']
        );
    }

    public function validateToken(string $token): bool
    {
        return $this->tokens->validate($token);
    }

    public function logout(string $token): void
    {
        $this->tokens->revoke($token);
    }

    public function checkPermission(User $user, string $permission): bool
    {
        return $this->roles->hasPermission($user->role, $permission);
    }

    private function verifyCredentials(array $credentials): AuthResult
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        if (!$user->is_active) {
            throw new AuthException('Account is inactive');
        }

        $token = $this->tokens->generate($user);
        
        return new AuthResult($user, $token);
    }
}

class TokenManager
{
    private const TOKEN_TTL = 3600;

    public function generate(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        
        Cache::put(
            "auth.token.{$token}",
            ['user_id' => $user->id, 'created_at' => time()],
            self::TOKEN_TTL
        );
        
        return $token;
    }

    public function validate(string $token): bool
    {
        $data = Cache::get("auth.token.{$token}");
        
        if (!$data) {
            return false;
        }

        if (time() - $data['created_at'] > self::TOKEN_TTL) {
            $this->revoke($token);
            return false;
        }

        return true;
    }

    public function revoke(string $token): void
    {
        Cache::forget("auth.token.{$token}");
    }
}

class RoleManager
{
    public function hasPermission(Role $role, string $permission): bool
    {
        return Cache::remember(
            "role.{$role->id}.permission.{$permission}",
            3600,
            fn() => $role->permissions()->where('name', $permission)->exists()
        );
    }

    public function assignRole(User $user, string $role): void
    {
        $roleModel = Role::where('name', $role)->firstOrFail();
        $user->role()->associate($roleModel);
        $user->save();
    }
}

class UserRepository
{
    public function create(array $data): User
    {
        return DB::transaction(function() use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => true
            ]);

            if (isset($data['role'])) {
                $role = Role::where('name', $data['role'])->firstOrFail();
                $user->role()->associate($role);
                $user->save();
            }

            return $user;
        });
    }

    public function findByEmail(string $email): ?User
    {
        return User::with('role.permissions')->where('email', $email)->first();
    }
}

class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active'
    ];

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}

class Role extends Model
{
    protected $fillable = [
        'name',
        'description'
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

class Permission extends Model
{
    protected $fillable = [
        'name',
        'description'
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}

class AuthResult
{
    public function __construct(
        public readonly User $user,
        public readonly string $token
    ) {}
}
