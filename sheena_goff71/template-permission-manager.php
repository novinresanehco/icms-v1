<?php

namespace App\Core\Template\Permissions;

use Illuminate\Support\Collection;
use App\Core\Template\Exceptions\PermissionException;
use Illuminate\Contracts\Auth\Access\Gate;

class PermissionManager
{
    private Collection $permissions;
    private Collection $roles;
    private Gate $gate;
    private PermissionCache $cache;
    private array $config;

    public function __construct(Gate $gate, PermissionCache $cache, array $config = [])
    {
        $this->permissions = new Collection();
        $this->roles = new Collection();
        $this->gate = $gate;
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Register a template permission
     *
     * @param string $name
     * @param array $options
     * @return TemplatePermission
     */
    public function register(string $name, array $options = []): TemplatePermission
    {
        $permission = new TemplatePermission($name, $options);
        $this->permissions->put($name, $permission);
        
        // Register with Laravel Gate
        $this->gate->define($name, function ($user) use ($permission) {
            return $this->checkPermission($user, $permission);
        });

        return $permission;
    }

    /**
     * Check if user has permission
     *
     * @param mixed $user
     * @param string|TemplatePermission $permission
     * @param array $context
     * @return bool
     */
    public function check($user, $permission, array $context = []): bool
    {
        if (is_string($permission)) {
            $permission = $this->getPermission($permission);
        }

        // Check cache first
        $cacheKey = $this->getCacheKey($user, $permission, $context);
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $result = $this->checkPermission($user, $permission, $context);
        
        // Cache the result
        $this->cache->put($cacheKey, $result);
        
        return $result;
    }

    /**
     * Add role
     *
     * @param string $name
     * @param array $permissions
     * @return void
     */
    public function addRole(string $name, array $permissions = []): void
    {
        $role = new TemplateRole($name, $permissions);
        $this->roles->put($name, $role);
    }

    /**
     * Get permission instance
     *
     * @param string $name
     * @return TemplatePermission
     */
    protected function getPermission(string $name): TemplatePermission
    {
        if (!$this->permissions->has($name)) {
            throw new PermissionException("Permission not found: {$name}");
        }
        
        return $this->permissions->get($name);
    }

    /**
     * Check user permission
     *
     * @param mixed $user
     * @param TemplatePermission $permission
     * @param array $context
     * @return bool
     */
    protected function checkPermission($user, TemplatePermission $permission, array $context = []): bool
    {
        // Check super admin
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Check user roles
        foreach ($this->getUserRoles($user) as $role) {
            if ($this->roleHasPermission($role, $permission->getName())) {
                return true;
            }
        }

        // Check specific permission
        return $this->userHasPermission($user, $permission, $context);
    }

    /**
     * Check if user is super admin
     *
     * @param mixed $user
     * @return bool
     */
    protected function isSuperAdmin($user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    /**
     * Get user roles
     *
     * @param mixed $user
     * @return array
     */
    protected function getUserRoles($user): array
    {
        if (method_exists($user, 'getRoles')) {
            return $user->getRoles();
        }
        
        return [];
    }

    /**
     * Check if role has permission
     *
     * @param string $role
     * @param string $permission
     * @return bool
     */
    protected function roleHasPermission(string $role, string $permission): bool
    {
        if (!$this->roles->has($role)) {
            return false;
        }

        return $this->roles->get($role)->hasPermission($permission);
    }

    /**
     * Check if user has specific permission
     *
     * @param mixed $user
     * @param TemplatePermission $permission
     * @param array $context
     * @return bool
     */
    protected function userHasPermission($user, TemplatePermission $permission, array $context): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission->getName(), $context);
        }

        return false;
    }

    /**
     * Generate cache key
     *
     * @param mixed $user
     * @param TemplatePermission $permission
     * @param array $context
     * @return string
     */
    protected function getCacheKey($user, TemplatePermission $permission, array $context): string
    {
        return sprintf(
            'permission:%s:%s:%s',
            $user->id ?? 'guest',
            $permission->getName(),
            md5(serialize($context))
        );
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'super_admin_role' => 'super-admin',
            'guest_role' => 'guest'
        ];
    }
}

class TemplatePermission
{
    private string $name;
    private array $options;

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        $this->options = array_merge([
            'description' => '',
            'group' => 'default',
            'dependencies' => []
        ], $options);
    }

    /**
     * Get permission name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get permission options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get permission dependencies
     *
     * @return array
     */
    public function getDependencies(): array
    {
        return $this->options['dependencies'];
    }
}

class TemplateRole
{
    private string $name;
    private array $permissions;

    public function __construct(string $name, array $permissions = [])
    {
        $this->name = $name;
        $this->permissions = $permissions;
    }

    /**
     * Check if role has permission
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions) ||
               in_array('*', $this->permissions);
    }

    /**
     * Add permission to role
     *
     * @param string $permission
     * @return void
     */
    public function addPermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            $this->permissions[] = $permission;
        }
    }

    /**
     * Remove permission from role
     *
     * @param string $permission
     * @return void
     */
    public function removePermission(string $permission): void
    {
        $this->permissions = array_diff($this->permissions, [$permission]);
    }
}

class PermissionCache
{
    private array $cache = [];
    private int $ttl;

    public function __construct(int $ttl = 3600)
    {
        $this->ttl = $ttl;
    }

    /**
     * Get cached permission
     *
     * @param string $key
     * @return bool|null
     */
    public function get(string $key): ?bool
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        [$value, $expiry] = $this->cache[$key];

        if ($expiry < time()) {
            unset($this->cache[$key]);
            return null;
        }

        return $value;
    }

    /**
     * Cache permission check result
     *
     * @param string $key
     * @param bool $value
     * @return void
     */
    public function put(string $key, bool $value): void
    {
        $this->cache[$key] = [$value, time() + $this->ttl];
    }

    /**
     * Clear permission cache
     *
     * @return void
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Permissions\PermissionManager;
use App\Core\Template\Permissions\PermissionCache;

class PermissionServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(PermissionManager::class, function ($app) {
            return new PermissionManager(
                $app['gate'],
                new PermissionCache(),
                config('permissions')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $manager = $this->app->make(PermissionManager::class);

        // Register default roles
        $manager->addRole('admin', ['*']);
        $manager->addRole('editor', [
            'template.view',
            'template.edit',
            'template.create'
        ]);
        $manager->addRole('viewer', ['template.view']);

        // Add Blade directive
        $this->app['blade.compiler']->directive('can', function ($expression) {
            return "<?php if (app(App\Core\Template\Permissions\PermissionManager::class)->check(auth()->user(), {$expression})): ?>";
        });

        $this->app['blade.compiler']->directive('endcan', function () {
            return "<?php endif; ?>";
        });
    }
}
