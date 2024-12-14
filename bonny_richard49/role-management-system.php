// src/Core/Security/RoleManager.php
<?php

namespace App\Core\Security;

use App\Core\Interfaces\RoleManagerInterface;
use App\Core\Models\{Role, User};
use App\Core\Services\{ValidationService, AuditLogger, CacheManager};
use App\Core\Repositories\RoleRepository;
use App\Core\Exceptions\{UnauthorizedRoleAssignmentException, SystemRoleDeleteException};
use Illuminate\Support\Facades\DB;

class RoleManager implements RoleManagerInterface
{
    private RoleRepository $roles;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private CacheManager $cache;

    public function __construct(
        RoleRepository $roles,
        ValidationService $validator,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->roles = $roles;
        $this->validator = $validator; 
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function createRole(array $data): Role 
    {
        DB::beginTransaction();
        
        try {
            // Validate
            $validated = $this->validator->validateRole($data);
            
            // Create role
            $role = $this->roles->create($validated);
            
            // Clear cache
            $this->cache->tags(['roles'])->flush();
            
            // Log
            $this->auditLogger->logRoleCreated($role);
            
            DB::commit();
            return $role;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logRoleCreationFailed($data, $e);
            throw $e;
        }
    }

    public function assignRole(User $user, Role $role): void
    {
        if (!$this->validator->canAssignRole($user, $role)) {
            throw new UnauthorizedRoleAssignmentException();
        }

        DB::transaction(function() use ($user, $role) {
            $user->roles()->sync([$role->id]);
            $this->auditLogger->logRoleAssigned($user, $role);
        });
    }

    public function verifyAccess(User $user, string $permission): bool
    {
        return $this->cache->rememberForever(
            "user.{$user->id}.permission.{$permission}",
            fn() => $user->hasPermission($permission)
        );
    }

    public function syncPermissions(Role $role, array $permissions): void
    {
        DB::transaction(function() use ($role, $permissions) {
            $role->syncPermissions($permissions);
            $this->auditLogger->logPermissionsSync($role, $permissions);
            $this->cache->tags(['permissions'])->flush();
        });
    }
}

// src/Core/Repositories/RoleRepository.php
<?php

namespace App\Core\Repositories;

use App\Core\Interfaces\RoleRepositoryInterface;
use App\Core\Models\Role;
use App\Core\Exceptions\SystemRoleDeleteException;

class RoleRepository implements RoleRepositoryInterface 
{
    private Role $model;
    
    public function __construct(Role $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Role
    {
        return $this->model->create($data);
    }

    public function findById(int $id): ?Role
    {
        return $this->model->find($id);
    }

    public function findBySlug(string $slug): ?Role 
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function delete(Role $role): bool
    {
        if ($role->is_system) {
            throw new SystemRoleDeleteException();
        }
        return $role->delete();
    }
}

// config/roles.php
<?php

return [
    'validation' => [
        'rules' => [
            'name' => 'required|string|max:50',
            'slug' => 'required|string|max:50|unique:roles',
            'level' => 'required|integer|min:0',
            'is_system' => 'boolean'
        ]
    ],

    'cache' => [
        'ttl' => 3600,
        'tags' => [
            'roles',
            'permissions'
        ]
    ],

    'audit' => [
        'enabled' => true,
        'events' => [
            'role_created',
            'role_updated',
            'role_deleted',
            'permissions_synced',
            'role_assigned'
        ]
    ],

    'security' => [
        'system_roles' => [
            'protected' => true,
            'modification' => false,
            'deletion' => false
        ],
        'assignment' => [
            'validation' => true,
            'audit' => true,
            'cache_clear' => true
        ]
    ]
];

// tests/Unit/RoleTest.php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Core\Models\{Role, Permission};
use App\Core\Exceptions\SystemRoleDeleteException;

class RoleTest extends TestCase 
{
    public function test_create_role_with_strict_validation()
    {
        $data = [
            'name' => 'Editor',
            'slug' => 'editor',
            'level' => 2,
            'is_system' => false
        ];

        $role = $this->roleManager->createRole($data);
        
        $this->assertDatabaseHas('roles', $data);
        $this->assertInstanceOf(Role::class, $role);
    }

    public function test_cannot_delete_system_role()
    {
        $this->expectException(SystemRoleDeleteException::class);
        
        $role = Role::factory()->create(['is_system' => true]);
        $this->roleManager->delete($role);
    }

    public function test_role_permission_sync_with_audit()
    {
        $role = Role::factory()->create();
        $permissions = Permission::factory(3)->create();
        
        $this->roleManager->syncPermissions($role, $permissions->pluck('id')->toArray());
        
        $this->assertCount(3, $role->fresh()->permissions);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'permissions_synced',
            'auditable_type' => Role::class,
            'auditable_id' => $role->id
        ]);
    }
}