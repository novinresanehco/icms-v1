<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{User, Role, Permission};
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
        $this->seedUsers();
        $this->assignRoles();
    }

    protected function seedPermissions(): void
    {
        $permissions = [
            // Content permissions
            ['name' => 'content.create', 'display_name' => 'Create Content', 'module' => 'content'],
            ['name' => 'content.edit', 'display_name' => 'Edit Content', 'module' => 'content'],
            ['name' => 'content.delete', 'display_name' => 'Delete Content', 'module' => 'content'],
            ['name' => 'content.publish', 'display_name' => 'Publish Content', 'module' => 'content'],

            // Media permissions
            ['name' => 'media.upload', 'display_name' => 'Upload Media', 'module' => 'media'],
            ['name' => 'media.delete', 'display_name' => 'Delete Media', 'module' => 'media'],

            // Template permissions
            ['name' => 'template.create', 'display_name' => 'Create Templates', 'module' => 'template'],
            ['name' => 'template.edit', 'display_name' => 'Edit Templates', 'module' => 'template'],
            ['name' => 'template.delete', 'display_name' => 'Delete Templates', 'module' => 'template'],

            // User management
            ['name' => 'user.create', 'display_name' => 'Create Users', 'module' => 'user'],
            ['name' => 'user.edit', 'display_name' => 'Edit Users', 'module' => 'user'],
            ['name' => 'user.delete', 'display_name' => 'Delete Users', 'module' => 'user'],

            // Role management
            ['name' => 'role.create', 'display_name' => 'Create Roles', 'module' => 'role'],
            ['name' => 'role.edit', 'display_name' => 'Edit Roles', 'module' => 'role'],
            ['name' => 'role.delete', 'display_name' => 'Delete Roles', 'module' => 'role'],

            // System settings
            ['name' => 'settings.view', 'display_name' => 'View Settings', 'module' => 'settings'],
            ['name' => 'settings.edit', 'display_name' => 'Edit Settings', 'module' => 'settings'],
        ];

        foreach ($permissions as $permission) {
            Permission::create(array_merge($permission, [
                'is_system' => true,
                'description' => 'System generated permission'
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

        foreach ($roles as $role) {
            $permissions = $role['permissions'];
            unset($role['permissions']);
            
            $role = Role::create($role);
            $role->permissions()->sync($permissions);
        }
    }

    protected function seedUsers(): void
    {
        User::create([
            'name' => 'System Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'email_verified_at' => now(),
            'is_admin' => true,
            'is_active' => true
        ]);

        User::create([
            'name' => 'Content Editor',
            'email' => 'editor@example.com',
            'password' => Hash::make('editor123'),
            'email_verified_at' => now(),
            'is_active' => true
        ]);

        User::create([
            'name' => 'Content Author',
            'email' => 'author@example.com',
            'password' => Hash::make('author123'),
            'email_verified_at' => now(),
            'is_active' => true
        ]);
    }

    protected function assignRoles(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();
        $editor = User::where('email', 'editor@example.com')->first();
        $author = User::where('email', 'author@example.com')->first();

        $admin->roles()->sync(Role::where('name', 'admin')->first());