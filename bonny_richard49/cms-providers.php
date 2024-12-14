<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class CMSServiceProvider extends ServiceProvider
{
    private array $managers = [
        'content' => [
            'manager' => \App\Core\CMS\ContentManager::class,
            'repository' => \App\Core\CMS\ContentRepository::class,
            'validator' => \App\Core\CMS\ContentValidator::class,
            'cache' => \App\Core\CMS\CacheManager::class
        ],
        'media' => [
            'manager' => \App\Core\CMS\MediaManager::class,
            'repository' => \App\Core\CMS\MediaRepository::class,
            'validator' => \App\Core\CMS\MediaValidator::class,
            'processor' => \App\Core\CMS\ImageProcessor::class
        ],
        'auth' => [
            'manager' => \App\Core\Auth\AuthManager::class,
            'repository' => \App\Core\Auth\UserRepository::class,
            'roles' => \App\Core\Auth\RoleRepository::class,
            'permissions' => \App\Core\Auth\PermissionRepository::class
        ],
        'template' => [
            'manager' => \App\Core\Template\TemplateManager::class,
            'repository' => \App\Core\Template\TemplateRepository::class,
            'validator' => \App\Core\Template\TemplateValidator::class,
            'compiler' => \App\Core\Template\TemplateCompiler::class
        ],
        'plugin' => [
            'manager' => \App\Core\Plugin\PluginManager::class,
            'repository' => \App\Core\Plugin\PluginRepository::class,
            'validator' => \App\Core\Plugin\PluginValidator::class,
            'sandbox' => \App\Core\Plugin\SandboxManager::class,
            'resolver' => \App\Core\Plugin\DependencyResolver::class
        ],
        'workflow' => [
            'manager' => \App\Core\Workflow\WorkflowManager::class,
            'validator' => \App\Core\Workflow\WorkflowValidator::class,
            'versions' => \App\Core\Workflow\VersionManager::class,
            'states' => \App\Core\Workflow\StateManager::class
        ],
        'search' => [
            'manager' => \App\Core\Search\SearchManager::class,
            'indexer' => \App\Core\Search\SearchIndexer::class,
            'validator' => \App\Core\Search\SearchValidator::class,
            'builder' => \App\Core\Search\QueryBuilder::class,
            'formatter' => \App\Core\Search\ResultFormatter::class
        ],
        'analytics' => [
            'manager' => \App\Core\Analytics\AnalyticsManager::class,
            'collector' => \App\Core\Analytics\MetricsCollector::class,
            'aggregator' => \App\Core\Analytics\DataAggregator::class,
            'reporter' => \App\Core\Analytics\ReportGenerator::class
        ],
        'monitor' => [
            'manager' => \App\Core\Monitor\MonitoringManager::class,
            'metrics' => \App\Core\Monitor\MetricsCollector::class,
            'health' => \App\Core\Monitor\HealthChecker::class,
            'alerts' => \App\Core\Monitor\AlertManager::class
        ],
        'deploy' => [
            'manager' => \App\Core\Deployment\DeploymentManager::class,
            'state' => \App\Core\Deployment\StateManager::class,
            'migrations' => \App\Core\Deployment\MigrationManager::class,
            'backup' => \App\Core\Deployment\BackupManager::class,
            'verify' => \App\Core\Deployment\VerificationManager::class
        ]
    ];

    public function register(): void
    {
        $this->registerSecurity();
        $this->registerCoreServices();
        $this->registerManagers();
    }

    private function registerSecurity(): void
    {
        $this->app->singleton(SecurityManager::class, function ($app) {
            return new SecurityManager(
                $app->make(ValidationService::class),
                $app->make(EncryptionService::class),
                $app->make(AuditLogger::class),
                $app->make(AccessControl::class),
                config('security')
            );
        });

        $this->app->singleton(ValidationService::class);
        $this->app->singleton(EncryptionService::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(AccessControl::class);
    }

    private function registerCoreServices(): void
    {
        $this->app->singleton(CacheManager::class);
        $this->app->singleton(EventDispatcher::class);
        $this->app->singleton(LogManager::class);
    }

    private function registerManagers(): void
    {
        foreach ($this->managers as $name => $components) {
            $this->registerManagerComponents($name, $components);
        }
    }

    private function registerManagerComponents(string $name, array $components): void
    {
        foreach ($components as $type => $class) {
            $this->app->singleton($class, function ($app) use ($class, $name) {
                return new $class(...$this->resolveConstructorParameters($class, $name));
            });
        }
    }

    private function resolveConstructorParameters($class, string $name): array
    {
        $reflector = new \ReflectionClass($class);
        $constructor = $reflector->getConstructor();

        if (!$constructor) {
            return [];
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = $this->resolveParameter($parameter, $name);
        }

        return $parameters;
    }

    private function resolveParameter(\ReflectionParameter $parameter, string $name): mixed
    {
        $type = $parameter->getType();
        
        if (!$type || $type->isBuiltin()) {
            return $this->resolveScalarParameter($parameter, $name);
        }

        $typeName = $type->getName();
        
        if ($this->app->has($typeName)) {
            return $this->app->make($typeName);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new \RuntimeException(
            "Unable to resolve parameter {$parameter->getName()} of type {$typeName}"
        );
    }

    private function resolveScalarParameter(\ReflectionParameter $parameter, string $name): mixed
    {
        if ($parameter->getName() === 'config') {
            return config("cms.{$name}");
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new \RuntimeException(
            "Unable to resolve scalar parameter {$parameter->getName()}"
        );
    }

    public function boot(): void
    {
        $this->bootSecurity();
        $this->bootCoreServices();
        $this->bootManagers();
    }

    private function bootSecurity(): void
    {
        /** @var SecurityManager $security */
        $security = $this->app->make(SecurityManager::class);
        $security->initialize();
    }

    private function bootCoreServices(): void
    {
        /** @var CacheManager $cache */
        $cache = $this->app->make(CacheManager::class);
        $cache->initialize();

        /** @var EventDispatcher $events */
        $events = $this->app->make(EventDispatcher::class);
        $events->initialize();
    }

    private function bootManagers(): void
    {
        foreach ($this->managers as $name => $components) {
            $this->bootManager($name, $components);
        }
    }

    private function bootManager(string $name, array $components): void
    {
        if (isset($components['manager'])) {
            /** @var mixed $manager */
            $manager = $this->app->make($components['manager']);
            
            if (method_exists($manager, 'initialize')) {
                $manager->initialize();
            }
        }
    }
}
