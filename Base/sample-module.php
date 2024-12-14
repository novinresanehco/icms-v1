<?php

namespace App\Modules\Content;

use App\Core\Modules\AbstractModule;

class ContentModule extends AbstractModule
{
    protected string $name = 'content';
    protected string $version = '1.0.0';
    protected array $dependencies = [
        'core' => '1.0.0'
    ];

    public function initialize(): void
    {
        // Register routes
        require __DIR__ . '/routes.php';
        
        // Register migrations
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
        
        // Register views
        $this->loadViewsFrom(__DIR__ . '/Resources/views', 'content');
    }

    public function install(): void
    {
        // Run migrations
        $this->artisan('migrate', ['--path' => 'app/Modules/Content/Database/Migrations']);
        
        // Add permissions
        $this->installPermissions();
        
        // Create default content types
        $this->createDefaultContentTypes();
    }

    public function uninstall(): void
    {
        // Rollback migrations
        $this->artisan('migrate:rollback', [
            '--path' => 'app/Modules/Content/Database/Migrations'
        ]);
    }

    protected function installPermissions(): void
    {
        $permissions = [
            'content.create',
            'content.edit',
            'content.delete',
            'content.publish'
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }
    }

    protected function createDefaultContentTypes(): void
    {
        $types = [
            [
                'name' => 'Page',
                'slug' => 'page',
                'fields' => [
                    'title' => ['type' => 'text', 'required' => true],
                    'content' => ['type' => 'wysiwyg', 'required' => true],
                    'meta_description' => ['type' => 'textarea']
                ]
            ],
            [
                'name' => 'Post',
                'slug' => 'post',
                'fields' => [
                    'title' => ['type' => 'text', 'required' => true],
                    'content' => ['type' => 'wysiwyg', 'required' => true],
                    'excerpt' => ['type' => 'textarea'],
                    'categories' => ['type' => 'taxonomy']
                ]
            ]
        ];

        foreach ($types as $type) {
            ContentType::create($type);
        }
    }
}
