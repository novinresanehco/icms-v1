<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{User, Role, Permission};
use App\Core\Security\{SecurityManager, ValidationService};
use Illuminate\Support\Facades\{Hash, DB, Log};

class DatabaseSeeder extends Seeder
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    
    public function __construct(
        SecurityManager $security,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function run(): void
    {
        DB::beginTransaction();
        try {
            // Create backup point before seeding
            $backupId = $this->security->createBackupPoint();
            
            // Execute seeding with monitoring
            $this->seedWithMonitoring(function() {
                $this->seedPermissions();
                $this->seedRoles();
                $this->seedUsers();
                $this->assignRoles();
            });
            
            DB::commit();
            $this->security->removeBackupPoint($backupId);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::critical('Seeding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function seedWithMonitoring(callable $operation): void
    {
        $startTime = microtime(true);
        
        try {
            $operation();
            
            // Log successful seeding metrics
            Log::info('Seeding completed', [
                'duration' => microtime(true) - $startTime,
                'permissions_count' => Permission::count(),
                'roles_count' => Role::count(),
                'users_count' => User::count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Seeding operation failed', [
                'duration' => microtime(true) - $startTime,
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function seedPermissions(): void
    {
        $permissions = [
            // Content permissions with strict validation
            ['name' => 'content.create', 'display_name' => 'Create Content', 'module' => 'content'],
            ['name' => 'content.edit', 'display_name' => 'Edit Content', 'module' => 'content'],
            ['name' => 'content.delete', 'display_name' => 'Delete Content', 'module' => 'content'],
            ['name' => 'content.publish', 'display_name' => 'Publish Content', 'module' => 'content'],

            // Media permissions with security validation
            ['name' => 'media.upload', 'display_name' => 'Upload Media', 'module' => 'media'],
            ['name' => 'media.delete', 'display_name' => 'Delete Media', 'module' => 'media'],

            // Templates with access control
            ['name' => 'template.create', 'display_name' => 'Create Templates', 'module' => 'template'],
            ['name' => 'template.edit', 'display_name' => 'Edit Templates', 'module' => 'template'],
            ['name' => 'template.delete', 'display_name' => 'Delete Templates', 'module' => 'template'],

            // User management with enhanced security
            ['name' => 'user.create', 'display_name' => 'Create Users', 'module' => 'user'],
            ['name' => 'user.edit', 'display_name' => 'Edit Users', 'module' => 'user'],
            ['name' => 'user.delete', 'display_name' => 'Delete Users', 'module' => 'user'],

            // Role management with strict validation
            ['name' => 'role.create', 'display_name' => 'Create Roles', 'module' => 'role'],
            ['name' => 'role.edit', 'display_name' => 'Edit Roles', 'module' => 'role'],
            ['name' => 'role.delete', 'display_name' => 'Delete Roles', 'module' => 'role'],

            // System settings with security checks
            ['name' => 'settings.view', 'display_name' => 'View Settings', 'module' => 'settings'],
            ['name' => 'settings.edit', 'display_name' => 'Edit Settings', 'module' => 'settings'],
        ];

        foreach ($permissions as $permission) {
            // Validate permission data before creation
            $validated = $this->validator->validatePermission($permission);
            
            Permission::create(array_merge($validated, [
                'is_system' => true,
                'description' => 'System generated permission',
                'created_by' => 'system',
                'checksum' => $this->security->generateChecksum($validated)
            ]));
        }
    }

    protected function seedRoles(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => Permission::all()
            ],
            [
                'name' => 'editor',
                'display_name' => 'Content Editor',
                'description' => 'Manage content and media',
                'is_system' => true,
                'permissions' => Permission::whereIn('module', ['content', 'media'])->get()
            ],
            [
                'name' => 'author',
                'display_name' => 'Content Author',
                'description' => 'Create and edit own content',
                'is_system' => true,
                'permissions' => Permission::where('module', 'content')
                    ->whereIn('name', ['content.create', 'content.edit'])
                    ->get()
            ]
        ];

        foreach ($roles as $roleData) {
            // Validate role data before creation
            $permissions = $roleData['permissions'];
            unset($roleData['permissions']);
            
            $validated = $this->validator->validateRole($roleData);
            
            $role = Role::create(array_merge($validated, [
                'created_by' => 'system',
                'checksum' => $this->security->generateChecksum($validated)
            ]));
            
            // Validate and assign permissions
            $validPermissions = $this->validator->validatePermissionAssignment(
                $role,
                $permissions
            );
            $role->permissions()->sync($validPermissions);
        }
    }

    protected function seedUsers(): void
    {
        $users = [
            [
                'name' => 'System Admin',
                'email' => 'admin@example.com',
                'password' => 'admin123',
                'is_admin' => true
            ],
            [
                'name' => 'Content Editor',
                'email' => 'editor@example.com',
                'password' => 'editor123'
            ],
            [
                'name' => 'Content Author',
                'email' => 'author@example.com',
                'password' => 'author123'
            ]
        ];

        foreach ($users as $userData) {
            // Validate user data before creation
            $validated = $this->validator->validateUser($userData);
            
            // Create user with secure password hashing
            User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'is_admin' => $validated['is_admin'] ?? false,
                'is_active' => true,
                'created_by' => 'system',
                'checksum' => $this->security->generateChecksum($validated)
            ]);
        }
    }

    protected function assignRoles(): void
    {
        $assignments = [
            'admin@example.com' => ['admin'],
            'editor@example.com' => ['editor'],
            'author@example.com' => ['author']
        ];

        foreach ($assignments as $email => $roleNames) {
            $user = User::where('email', $email)->firstOrFail();
            $roles = Role::whereIn('name', $roleNames)->get();
            
            // Validate role assignment
            $validRoles = $this->validator->validateRoleAssignment($user, $roles);
            $user->roles()->sync($validRoles);
        }
    }
}
