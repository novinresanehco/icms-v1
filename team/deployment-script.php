<?php

namespace App\Core\Deploy;

use Illuminate\Support\Facades\{DB, Artisan, Schema};

class DeploymentManager
{
    public function deploy(): void
    {
        DB::beginTransaction();
        
        try {
            // Verify environment
            $this->verifyEnvironment();
            
            // Run migrations
            Artisan::call('migrate:fresh');
            
            // Seed initial data
            $this->seedCriticalData();
            
            // Initialize security
            $this->initializeSecurity();
            
            // Setup caching
            $this->setupCache();
            
            // Verify installation
            $this->verifyInstallation();
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new DeploymentException('Deployment failed: ' . $e->getMessage());
        }
    }

    private function verifyEnvironment(): void
    {
        if (!app()->environment('production')) {
            throw new DeploymentException('Must be in production environment');
        }

        $requiredExtensions = ['pdo', 'mbstring', 'openssl', 'redis'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                throw new DeploymentException("Required extension {$ext} not loaded");
            }
        }
    }

    private function seedCriticalData(): void
    {
        // Create admin role
        $role = DB::table('roles')->insertGetId([
            'name' => 'admin',
            'description' => 'System Administrator'
        ]);

        // Create admin permissions
        $permissions = [
            'manage_users',
            'manage_content',
            'manage_system'
        ];

        foreach ($permissions as $permission) {
            $permId = DB::table('permissions')->insertGetId([
                'name' => $permission,
                'description' => ucfirst(str_replace('_', ' ', $permission))
            ]);

            DB::table('role_permission')->insert([
                'role_id' => $role,
                'permission_id' => $permId
            ]);
        }

        // Create admin user
        DB::table('users')->insert([
            'name' => 'Admin',
            'email' => env('ADMIN_EMAIL'),
            'password' => bcrypt(env('ADMIN_PASSWORD')),
            'role_id' => $role,
            'is_active' => true
        ]);

        // Create default template
        DB::table('templates')->insert([
            'name' => 'default',
            'path' => 'templates.default',
            'type' => 'blade',
            'is_active' => true
        ]);
    }

    private function initializeSecurity(): void
    {
        // Generate new application key
        Artisan::call('key:generate');
        
        // Clear all caches
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        
        // Optimize for production
        Artisan::call('optimize');
        
        // Setup storage links
        Artisan::call('storage:link');
    }

    private function setupCache(): void
    {
        // Initialize Redis connection
        $redis = app()->make('redis');
        $redis->flushdb();

        // Warm up cache
        $this->warmCache();
    }

    private function warmCache(): void
    {
        // Cache roles and permissions
        $roles = DB::table('roles')->get();
        foreach ($roles as $role) {
            $permissions = DB::table('role_permission')
                ->where('role_id', $role->id)
                ->pluck('permission_id')
                ->toArray();
            
            cache()->forever("role.{$role->id}.permissions", $permissions);
        }

        // Cache templates
        $templates = DB::table('templates')
            ->where('is_active', true)
            ->get();
        
        foreach ($templates as $template) {
            cache()->forever("template.{$template->name}", $template);
        }
    }

    private function verifyInstallation(): void
    {
        // Verify database tables
        $requiredTables = [
            'users',
            'roles',
            'permissions',
            'contents',
            'categories',
            'templates'
        ];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                throw new DeploymentException("Required table {$table} not found");
            }
        }

        // Verify admin user
        $admin = DB::table('users')
            ->where('email', env('ADMIN_EMAIL'))
            ->first();

        if (!$admin) {
            throw new DeploymentException('Admin user not created');
        }

        // Verify Redis connection
        if (!cache()->ping()) {
            throw new DeploymentException('Redis connection failed');
        }
    }
}
