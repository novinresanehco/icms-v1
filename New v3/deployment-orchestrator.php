<?php

namespace App\Core\Deployment;

use App\Core\System\{
    EnvironmentManager,
    SecurityManager, 
    BackupManager,
    HealthMonitor
};

class DeploymentOrchestrator
{
    private EnvironmentManager $env;
    private SecurityManager $security;
    private BackupManager $backup;
    private HealthMonitor $health;

    public function deploy(DeploymentConfig $config): DeploymentResult
    {
        try {
            // Pre-deployment checks
            $this->validateEnvironment();
            $this->createBackup();
            
            // Start deployment process
            $this->health->startDeploymentMode();
            
            // Execute deployment steps
            $this->executeDeploymentSteps($config);
            
            // Post-deployment validation
            $this->validateDeployment();
            
            return new DeploymentResult(true, 'Deployment completed successfully');
            
        } catch (\Exception $e) {
            $this->handleDeploymentFailure($e);
            throw $e;
        } finally {
            $this->health->endDeploymentMode();
        }
    }

    protected function validateEnvironment(): void 
    {
        if (!$this->env->validate()) {
            throw new InvalidEnvironmentException();
        }

        if (!$this->security->validateDeploymentSecurity()) {
            throw new SecurityValidationException();
        }
    }

    protected function createBackup(): string
    {
        $backupId = $this->backup->createPreDeploymentBackup();
        
        if (!$this->backup->verify($backupId)) {
            throw new BackupFailedException();
        }

        return $backupId;
    }

    protected function executeDeploymentSteps(DeploymentConfig $config): void
    {
        // Database migrations
        if ($config->hasDatabaseChanges()) {
            $this->executeMigrations($config);
        }

        // Asset compilation and publishing
        if ($config->hasAssetChanges()) {
            $this->publishAssets($config);
        }

        // Cache clearing
        $this->clearCaches($config);

        // Service restarts
        if ($config->requiresServiceRestart()) {
            $this->restartServices($config);
        }
    }

    protected function validateDeployment(): void
    {
        if (!$this->health->checkSystemHealth()) {
            throw new HealthCheckFailedException();
        }

        if (!$this->security->validatePostDeployment()) {
            throw new PostDeploymentSecurityException();
        }
    }

    protected function handleDeploymentFailure(\Exception $e): void
    {
        $this->backup->restoreLatest();
        $this->health->recordDeploymentFailure($e);
        $this->notifyAdministrators($e);
    }

    protected function executeMigrations(DeploymentConfig $config): void
    {
        try {
            DB::beginTransaction();
            
            // Run migrations
            Artisan::call('migrate', [
                '--force' => true,
                '--step' => true
            ]);
            
            // Verify migration success
            if (!$this->verifyMigrations()) {
                throw new MigrationFailedException();
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function publishAssets(DeploymentConfig $config): void
    {
        Artisan::call('queue:restart');
        Artisan::call('view:clear');
        Artisan::call('cache:clear');
        
        // Publish assets
        foreach ($config->getAssetPublishers() as $publisher) {
            $publisher->publish();
        }
    }

    protected function clearCaches(DeploymentConfig $config): void
    {
        foreach ($config->getCachesToClear() as $cache) {
            Cache::tags($cache)->flush();
        }
    }

    protected function restartServices(DeploymentConfig $config): void
    {
        foreach ($config->getServicesToRestart() as $service) {
            $this->restartService($service);
        }
    }

    protected function verifyMigrations(): bool
    {
        return DB::table('migrations')
            ->orderBy('batch', 'desc')
            ->first()
            ->batch === DB::table('migrations')
            ->max('batch');
    }

    protected function restartService(string $service): void
    {
        exec("sudo systemctl restart $service", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new ServiceRestartException($service);
        }
    }

    protected function notifyAdministrators(\Exception $e): void
    {
        Notification::route('mail', config('deployment.admin_email'))
            ->notify(new DeploymentFailedNotification($e));
    }
}
