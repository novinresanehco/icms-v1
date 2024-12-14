<?php

namespace App\Core\Deployment;

final class DeploymentManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private BackupService $backup;
    private VersionControl $version;
    private MonitoringService $monitor;
    private AuditService $audit;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        BackupService $backup,
        VersionControl $version,
        MonitoringService $monitor,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->backup = $backup;
        $this->version = $version;
        $this->monitor = $monitor;
        $this->audit = $audit;
    }

    public function deploy(Release $release, DeploymentContext $context): void
    {
        $deploymentId = $this->audit->startDeployment($release, $context);
        
        try {
            // Pre-deployment validation
            $this->preDeploymentChecks($release, $context);
            
            // Create system snapshot
            $backupId = $this->backup->createSystemSnapshot();
            
            // Start deployment monitoring
            $monitoringId = $this->monitor->startDeployment();
            
            try {
                // Execute deployment steps
                $this->executeDeployment($release, $context);
                
                // Post-deployment validation
                $this->postDeploymentValidation($release);
                
                // Record successful deployment
                $this->audit->recordDeploymentSuccess($deploymentId);
                
            } catch (\Throwable $e) {
                // Restore from backup
                $this->backup->restoreSnapshot($backupId);
                throw $e;
            }
            
        } catch (\Throwable $e) {
            // Record deployment failure
            $this->audit->recordDeploymentFailure($deploymentId, $e);
            throw new DeploymentException(
                'Deployment failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function preDeploymentChecks(Release $release, DeploymentContext $context): void
    {
        // Validate release
        if (!$this->validator->validateRelease($release)) {
            throw new ValidationException('Release validation failed');
        }

        // Security checks
        if (!$this->security->verifyDeploymentSecurity($context)) {
            throw new SecurityException('Security verification failed');
        }

        // System checks
        if (!$this->monitor->isSystemHealthy()) {
            throw new SystemException('System health check failed');
        }

        // Version compatibility
        if (!$this->version->isCompatibleRelease($release)) {
            throw new VersionException('Incompatible release version');
        }
    }

    private function executeDeployment(Release $release, DeploymentContext $context): void
    {
        // Update codebase
        $this->version->updateCodebase($release->getCode());
        
        // Update database
        $this->executeDatabaseMigrations($release->getMigrations());
        
        // Update configurations
        $this->updateConfigurations($release->getConfigs());
        
        // Clear caches
        $this->clearSystemCaches();
        
        // Warm up caches
        $this->warmupCaches();
        
        // Update version
        $this->version->updateVersion($release->getVersion());
    }

    private function postDeploymentValidation(Release $release): void
    {
        // Verify system state
        if (!$this->monitor->verifySystemState()) {
            throw new SystemException('System state verification failed');
        }

        // Verify database state
        if (!$this->validator->verifyDatabaseState()) {
            throw new DatabaseException('Database state verification failed');
        }

        // Verify application state
        if (!$this->validator->verifyApplicationState()) {
            throw new ApplicationException('Application state verification failed');
        }

        // Security verification
        if (!$this->security->verifySystemSecurity()) {
            throw new SecurityException('Security verification failed');
        }
    }

    private function executeDatabaseMigrations(array $migrations): void
    {
        foreach ($migrations as $migration) {
            $this->executeMigration($migration);
        }
    }

    private function executeMigration(Migration $migration): void
    {
        DB::beginTransaction();
        
        try {
            // Execute migration
            $migration->up();
            
            // Verify migration
            if (!$this->validator->verifyMigration($migration)) {
                throw new MigrationException('Migration verification failed');
            }
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function clearSystemCaches(): void
    {
        Cache::flush();
        Config::clear();
        Route::clear();
        View::clear();
    }

    private function warmupCaches(): void
    {
        Config::cache();
        Route::cache();
        View::cache();
    }
}
