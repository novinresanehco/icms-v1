<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RepositoryInterface;
use App\Core\Repositories\Decorators\{
    CacheableRepository,
    EventAwareRepository,
    ValidatedRepository,
    MetricsAwareRepository,
    VersionedRepository,
    PermissionAwareRepository
};
use Illuminate\Container\Container;

class RepositoryFactory
{
    protected Container $container;
    protected array $config;
    protected array $decorators = [];

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = $config;
        $this->registerDefaultDecorators();
    }

    public function make(string $modelClass): RepositoryInterface
    {
        $baseRepository = $this->createBaseRepository($modelClass);
        return $this->applyDecorators($baseRepository, $modelClass);
    }

    protected function createBaseRepository(string $modelClass): RepositoryInterface
    {
        $model = $this->container->make($modelClass);
        $repositoryClass = $this->resolveRepositoryClass($modelClass);
        
        return new $repositoryClass($model);
    }

    protected function applyDecorators(RepositoryInterface $repository, string $modelClass): RepositoryInterface
    {
        $decorators = $this->getEnabledDecorators($modelClass);
        
        foreach ($decorators as $decorator) {
            $repository = $this->createDecorator($decorator, $repository);
        }

        return $repository;
    }

    protected function registerDefaultDecorators(): void
    {
        $this->decorators = [
            'validation' => [
                'class' => ValidatedRepository::class,
                'enabled' => true,
                'priority' => 100
            ],
            'permission' => [
                'class' => PermissionAwareRepository::class,
                'enabled' => true,
                'priority' => 90
            ],
            'version' => [
                'class' => VersionedRepository::class,
                'enabled' => true,
                'priority' => 80
            ],
            'event' => [
                'class' => EventAwareRepository::class,
                'enabled' => true,
                'priority' => 70
            ],
            'metrics' => [
                'class' => MetricsAwareRepository::class,
                'enabled' => true,
                'priority' => 60
            ],
            'cache' => [
                'class' => CacheableRepository::class,
                'enabled' => true,
                'priority' => 50
            ]
        ];
    }

    protected function getEnabledDecorators(string $modelClass): array
    {
        $modelConfig = $this->config['models'][$modelClass] ?? [];
        
        return collect($this->decorators)
            ->filter(function ($decorator, $key) use ($modelConfig) {
                return $modelConfig["enable_{$key}"] ?? $decorator['enabled'];
            })
            ->sortByDesc('priority')
            ->toArray();
    }

    protected function createDecorator(array $decorator, RepositoryInterface $repository): RepositoryInterface
    {
        $decoratorClass = $decorator['class'];
        $dependencies = $this->resolveDependencies($decoratorClass, $repository);
        
        return new $decoratorClass(...$dependencies);
    }

    protected function resolveDependencies(string $decoratorClass, RepositoryInterface $repository): array
    {
        $dependencies = [$repository];
        
        $constructor = (new \ReflectionClass($decoratorClass))->getConstructor();
        
        if (!$constructor) {
            return $dependencies;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getType() && $parameter->getType()->getName() !== RepositoryInterface::class) {
                $dependencies[] = $this->container->make($parameter->getType()->getName());
            }
        }

        return $dependencies;
    }

    protected function resolveRepositoryClass(string $modelClass): string
    {
        $modelName = class_basename($modelClass);
        $repositoryClass = "App\\Core\\Repositories\\{$modelName}Repository";
        
        if (!class_exists($repositoryClass)) {
            $repositoryClass = BaseRepository::class;
        }

        return $repositoryClass;
    }
}
