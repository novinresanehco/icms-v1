<?php

namespace App\Core\System;

class EnvironmentManager
{
    private ConfigurationValidator $validator;
    private SecurityManager $security;
    private HealthMonitor $health;
    private array $config;

    public function validateEnvironment(): bool
    {
        $checks = [
            $this->validateConfigurations(),
            $this->validateSecuritySettings(),
            $this->validateSystemResources(),
            $this->validateDependencies(),
            $this->validatePermissions()
        ];

        return !in_array(false, $checks, true);
    }

    public function updateEnvironment(array $config): void
    {
        DB::beginTransaction();
        try {
            // Validate new config
            if (!$this->validator->validate($config)) {
                throw new InvalidConfigurationException();
            }

            // Backup current settings
            $this->backupCurrentSettings();

            // Update environment variables
            $this->updateEnvFile($config);

            // Update system configs
            $this->updateSystemConfigs($config);

            // Clear relevant caches
            $this->clearConfigurationCaches();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->restoreSettings();
            throw $e;
        }
    }

    protected function validateConfigurations(): bool
    {
        foreach ($this->getRequiredConfigs() as $config) {
            if (!$this->validateConfig($config)) {
                return false;
            }
        }
        return true;
    }

    protected function validateSecuritySettings(): bool
    {
        return $this->security->validateEnvironmentSecurity([
            'encryption' => config('app.encryption'),
            'key' => config('app.key'),
            'cipher' => config('app.cipher')
        ]);
    }

    protected function validateSystemResources(): bool
    {
        return $this->health->checkSystemResources([
            'disk_space' => $this->getRequiredDiskSpace(),
            'memory' => $this->getRequiredMemory(),
            'cpu' => $this->getRequiredCPU()
        ]);
    }

    protected function validateDependencies(): bool
    {
        $dependencies = $this->getDependencyList();
        foreach ($dependencies as $dependency => $version) {
            if (!$this->checkDependency($dependency, $version)) {
                return false;
            }
        }
        return true;
    }

    protected function validatePermissions(): bool
    {
        $paths = $this->getRequiredPaths();
        foreach ($paths as $path => $permission) {
            if (!$this->checkPathPermission($path, $permission)) {
                return false;
            }
        }
        return true;
    }

    protected function backupCurrentSettings(): void
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $envContent = file_get_contents(base_path('.env'));
        file_put_contents(
            storage_path("env.backup_{$timestamp}"),
            $envContent
        );
    }

    protected function updateEnvFile(array $config): void
    {
        $envFile = base_path('.env');
        $envContent = file_get_contents($envFile);

        foreach ($config as $key => $value) {
            $envContent = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$value}",
                $envContent
            );
        }

        file_put_contents($envFile, $envContent);
    }

    protected function updateSystemConfigs(array $config): void
    {
        foreach ($config as $key => $value) {
            Config::set($key, $value);
        }
    }

    protected function clearConfigurationCaches(): void
    {
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');
    }

    protected function restoreSettings(): void
    {
        $latestBackup = $this->getLatestEnvBackup();
        if ($latestBackup) {
            file_put_contents(
                base_path('.env'),
                file_get_contents($latestBackup)
            );
            $this->clearConfigurationCaches();
        }
    }

    protected function checkDependency(string $dependency, string $version): bool
    {
        return version_compare(
            $this->getInstalledVersion($dependency),
            $version,
            '>='
        );
    }

    protected function checkPathPermission(string $path, int $permission): bool
    {
        return file_exists($path) && 
               decoct(fileperms($path) & 0777) == decoct($permission);
    }

    protected function getLatestEnvBackup(): ?string
    {
        $backups = glob(storage_path('env.backup_*'));
        return !empty($backups) ? end($backups) : null;
    }

    protected function getRequiredConfigs(): array
    {
        return [
            'app.key',
            'app.env',
            'app.debug',
            'database.default',
            'cache.default',
            'queue.default',
            'mail.default'
        ];
    }

    protected function getDependencyList(): array
    {
        return [
            'php' => '8.1.0',
            'mysql' => '8.0.0',
            'redis' => '6.0.0',
            'node' => '16.0.0'
        ];
    }

    protected function getRequiredPaths(): array
    {
        return [
            storage_path() => 0775,
            storage_path('logs') => 0775,
            storage_path('app/public') => 0775,
            base_path('bootstrap/cache') => 0775
        ];
    }
}
