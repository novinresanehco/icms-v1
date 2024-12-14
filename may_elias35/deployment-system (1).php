// File: app/Core/Deployment/Manager/DeploymentManager.php
<?php

namespace App\Core\Deployment\Manager;

class DeploymentManager
{
    protected EnvironmentManager $environmentManager;
    protected ReleaseManager $releaseManager;
    protected BackupManager $backupManager;
    protected DeploymentValidator $validator;

    public function deploy(Deployment $deployment): DeploymentResult
    {
        $this->validator->validate($deployment);
        
        DB::beginTransaction();
        try {
            // Create backup
            $backup = $this->backupManager->create();
            
            // Prepare release
            $release = $this->releaseManager->prepare($deployment);
            
            // Deploy to environment
            $this->environmentManager->deploy($release);
            
            // Activate release
            $this->releaseManager->activate($release);
            
            DB::commit();
            return new DeploymentResult($release);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($deployment, $e);
            throw new DeploymentException("Deployment failed: " . $e->getMessage());
        }
    }

    public function rollback(Release $release): void
    {
        try {
            $previousRelease = $this->releaseManager->getPreviousRelease($release);
            $this->environmentManager->rollback($release, $previousRelease);
            $this->releaseManager->setActive($previousRelease);
        } catch (\Exception $e) {
            throw new DeploymentException("Rollback failed: " . $e->getMessage());
        }
    }
}

// File: app/Core/Deployment/Release/ReleaseManager.php
<?php

namespace App\Core\Deployment\Release;

class ReleaseManager
{
    protected ReleaseRepository $repository;
    protected ReleaseBuilder $builder;
    protected VersionManager $versionManager;
    protected ArtifactStore $artifactStore;

    public function prepare(Deployment $deployment): Release
    {
        // Build release
        $release = $this->builder->build($deployment);
        
        // Store artifacts
        $this->storeArtifacts($release);
        
        // Generate version
        $version = $this->versionManager->generateVersion($release);
        $release->setVersion($version);
        
        // Save release
        return $this->repository->save($release);
    }

    public function activate(Release $release): void
    {
        $release->setActive(true);
        $release->setActivatedAt(now());
        $this->repository->save($release);
    }

    protected function storeArtifacts(Release $release): void
    {
        foreach ($release->getArtifacts() as $artifact) {
            $this->artifactStore->store($artifact);
        }
    }
}

// File: app/Core/Deployment/Environment/EnvironmentManager.php
<?php

namespace App\Core\Deployment\Environment;

class EnvironmentManager
{
    protected EnvironmentRepository $repository;
    protected ServerManager $serverManager;
    protected ConfigManager $configManager;
    protected ServiceManager $serviceManager;

    public function deploy(Release $release): void
    {
        $environment = $release->getEnvironment();
        
        // Update configurations
        $this->configManager->update($environment, $release);
        
        // Deploy to servers
        $this->deployToServers($environment, $release);
        
        // Update services
        $this->serviceManager->update($environment, $release);
        
        // Run post-deploy tasks
        $this->runPostDeployTasks($environment, $release);
    }

    protected function deployToServers(Environment $environment, Release $release): void
    {
        $servers = $this->serverManager->getServers($environment);
        
        foreach ($servers as $server) {
            $this->serverManager->deploy($server, $release);
        }
    }

    protected function runPostDeployTasks(Environment $environment, Release $release): void
    {
        foreach ($release->getPostDeployTasks() as $task) {
            $task->execute($environment);
        }
    }
}

// File: app/Core/Deployment/Validation/DeploymentValidator.php
<?php

namespace App\Core\Deployment\Validation;

class DeploymentValidator
{
    protected EnvironmentValidator $environmentValidator;
    protected ReleaseValidator $releaseValidator;
    protected DependencyValidator $dependencyValidator;

    public function validate(Deployment $deployment): bool
    {
        // Validate environment
        $this->environmentValidator->validate(
            $deployment->getEnvironment()
        );
        
        // Validate release
        $this->releaseValidator->validate(
            $deployment->getRelease()
        );
        
        // Validate dependencies
        $this->validateDependencies($deployment);
        
        // Additional validations
        $this->validateConfigurations($deployment);
        $this->validatePermissions($deployment);
        
        return true;
    }

    protected function validateDependencies(Deployment $deployment): void
    {
        $dependencies = $deployment->getDependencies();
        
        if (!$this->dependencyValidator->validate($dependencies)) {
            throw new ValidationException("Dependency validation failed");
        }
    }

    protected function validateConfigurations(Deployment $deployment): void
    {
        $configurations = $deployment->getConfigurations();
        foreach ($configurations as $config) {
            if (!$config->isValid()) {
                throw new ValidationException("Invalid configuration: " . $config->getName());
            }
        }
    }
}
