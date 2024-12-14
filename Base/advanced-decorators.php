<?php

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\RepositoryInterface;
use App\Core\Services\Metrics\MetricsCollector;
use App\Core\Services\Permission\PermissionManager;
use App\Core\Services\Version\VersionManager;
use Illuminate\Support\Facades\Log;

class MetricsAwareRepository implements RepositoryInterface
{
    protected RepositoryInterface $repository;
    protected MetricsCollector $metrics;

    public function __construct(RepositoryInterface $repository, MetricsCollector $metrics)
    {
        $this->repository = $repository;
        $this->metrics = $metrics;
    }

    public function find($id)
    {
        $startTime = microtime(true);
        try {
            $result = $this->repository->find($id);
            $this->recordMetrics('find', $startTime);
            return $result;
        } catch (\Exception $e) {
            $this->recordError('find', $e);
            throw $e;
        }
    }

    public function create(array $attributes)
    {
        $startTime = microtime(true);
        try {
            $result = $this->repository->create($attributes);
            $this->recordMetrics('create', $startTime);
            return $result;
        } catch (\Exception $e) {
            $this->recordError('create', $e);
            throw $e;
        }
    }

    protected function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->record("repository.{$operation}", [
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true),
            'timestamp' => now()
        ]);
    }

    protected function recordError(string $operation, \Exception $error): void
    {
        $this->metrics->incrementCounter("repository.errors.{$operation}");
    }
}

class VersionedRepository implements RepositoryInterface
{
    protected RepositoryInterface $repository;
    protected VersionManager $versionManager;

    public function __construct(RepositoryInterface $repository, VersionManager $versionManager)
    {
        $this->repository = $repository;
        $this->versionManager = $versionManager;
    }

    public function update($id, array $attributes)
    {
        $original = $this->repository->find($id);
        $result = $this->repository->update($id, $attributes);
        
        $this->versionManager->createVersion(
            $result,
            $original->toArray(),
            $attributes,
            auth()->id()
        );

        return $result;
    }

    public function getVersions($id): array
    {
        return $this->versionManager->getVersionHistory($id);
    }

    public function revertToVersion($id, string $versionId)
    {
        $version = $this->versionManager->getVersion($versionId);
        return $this->repository->update($id, $version->getData());
    }
}

class PermissionAwareRepository implements RepositoryInterface
{
    protected RepositoryInterface $repository;
    protected PermissionManager $permissions;

    public function __construct(RepositoryInterface $repository, PermissionManager $permissions)
    {
        $this->repository = $repository;
        $this->permissions = $permissions;
    }

    public function find($id)
    {
        $result = $this->repository->find($id);
        
        if (!$this->permissions->can('view', $result)) {
            throw new \App\Core\Exceptions\AccessDeniedException(
                "Access denied to view resource with ID: {$id}"
            );
        }

        return $result;
    }

    public function create(array $attributes)
    {
        if (!$this->permissions->can('create', $this->repository->getModelClass())) {
            throw new \App\Core\Exceptions\AccessDeniedException('Access denied to create resource');
        }

        return $this->repository->create($attributes);
    }

    public function update($id, array $attributes)
    {
        $model = $this->repository->find($id);
        
        if (!$this->permissions->can('update', $model)) {
            throw new \App\Core\Exceptions\AccessDeniedException(
                "Access denied to update resource with ID: {$id}"
            );
        }

        return $this->repository->update($id, $attributes);
    }
}
